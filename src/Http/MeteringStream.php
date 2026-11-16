<?php

declare(strict_types=1);

namespace Tokentify\Http;

use Psr\Http\Message\StreamInterface;
use Tokentify\TokenUsage;
use Tokentify\UsageParser;

/**
 * Decorates a streaming (`text/event-stream`) response body. Bytes pass through unchanged while SSE
 * `data:` lines are scanned for usage; when the stream ends (EOF, close(), detach() or garbage
 * collection) the merged usage is handed to $onComplete exactly once.
 *
 * Each chunk is inspected before it is returned, so a caller that stops reading at `[DONE]` still
 * gets metered. Rewinding and re-reading never counts the same bytes twice.
 */
final class MeteringStream implements StreamInterface
{
    private TokenUsage $usage;
    private string $buffer = '';
    private bool $finalized = false;
    private int $pos = 0;
    private int $seen = 0;

    /**
     * @param \Closure(array<string, int>): void $onComplete Receives the internal usage array
     */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly string $provider,
        private readonly \Closure $onComplete,
    ) {
        $this->usage = new TokenUsage();
    }

    public function __destruct()
    {
        $this->finalize();
    }

    /** True once usage has been delivered (or found empty). */
    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        try {
            $this->stream->close();
        } finally {
            $this->finalize();
        }
    }

    public function detach()
    {
        $this->finalize();

        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        $eof = $this->stream->eof();
        if ($eof) {
            $this->finalize();
        }

        return $eof;
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
        try {
            $this->pos = $this->stream->tell();
        } catch (\Throwable) {
            $this->pos = (int) $offset;
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    public function write($string): int
    {
        return $this->stream->write($string);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function read($length): string
    {
        $chunk = $this->stream->read($length);
        $this->observe($chunk);
        if ($chunk === '' || $this->stream->eof()) {
            $this->finalize();
        }

        return $chunk;
    }

    public function getContents(): string
    {
        $rest = $this->stream->getContents();
        $this->observe($rest);
        $this->finalize();

        return $rest;
    }

    public function getMetadata($key = null)
    {
        return $this->stream->getMetadata($key);
    }

    /** Scan only bytes not seen before (re-reads after a rewind are ignored). */
    private function observe(string $chunk): void
    {
        $start = $this->pos;
        $this->pos += strlen($chunk);
        if ($this->finalized || $this->pos <= $this->seen) {
            return;
        }
        $fresh = substr($chunk, max(0, $this->seen - $start));
        $this->seen = $this->pos;
        $this->process($fresh);
    }

    private function process(string $text): void
    {
        try {
            $this->buffer .= $text;
            while (($nl = strpos($this->buffer, "\n")) !== false) {
                $line = substr($this->buffer, 0, $nl);
                $this->buffer = (string) substr($this->buffer, $nl + 1);
                $this->handleLine($line);
            }
        } catch (\Throwable) {
            // Never disrupt the stream.
        }
    }

    private function handleLine(string $line): void
    {
        $event = self::parseSseData($line);
        if ($event === null) {
            return;
        }
        try {
            $chunk = UsageParser::fromSseEvent($event, $this->provider);
            if ($chunk->totalTokens() > 0 || $chunk->cacheTokens() > 0) {
                $this->usage = self::mergeUsage($this->usage, $chunk);
            }
        } catch (\Throwable) {
        }
    }

    private function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        if (trim($this->buffer) !== '') { // last SSE line without a trailing newline
            $line = $this->buffer;
            $this->buffer = '';
            $this->handleLine($line);
        }
        $usage = $this->usage;
        if ($usage->totalTokens() === 0 && $usage->cacheTokens() === 0) {
            return;
        }
        $payload = $usage->toArray();
        $payload['cache_read_input_tokens'] = $usage->cacheReadTokens;
        $payload['cache_creation_input_tokens'] = $usage->cacheWriteTokens;
        try {
            ($this->onComplete)($payload);
        } catch (\Throwable) {
        }
    }

    /**
     * Parse an SSE `data:` line as JSON. Null for other lines, `[DONE]` and invalid JSON.
     *
     * @return array<string, mixed>|null
     */
    public static function parseSseData(string $line): ?array
    {
        $line = trim($line);
        if (! str_starts_with($line, 'data:')) {
            return null;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '[DONE]') {
            return null;
        }
        $data = json_decode($payload, true);

        return is_array($data) ? $data : null;
    }

    /** Merge partial SSE usage fragments (Anthropic splits input / output across events). */
    public static function mergeUsage(TokenUsage $existing, TokenUsage $chunk): TokenUsage
    {
        return new TokenUsage(
            $chunk->inputTokens ?: $existing->inputTokens,
            $chunk->outputTokens ?: $existing->outputTokens,
            $chunk->cacheReadTokens ?: $existing->cacheReadTokens,
            $chunk->cacheWriteTokens ?: $existing->cacheWriteTokens,
        );
    }
}
