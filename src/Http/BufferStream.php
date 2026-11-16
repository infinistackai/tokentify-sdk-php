<?php

declare(strict_types=1);

namespace Tokentify\Http;

use Psr\Http\Message\StreamInterface;

/**
 * In-memory PSR-7 stream holding an already-read body. The transport uses it to hand a
 * non-seekable response body back to the caller after metering has read it.
 *
 * Parameters are untyped so it works with psr/http-message 1.1 and 2.0.
 */
final class BufferStream implements StreamInterface
{
    private int $pos = 0;
    private bool $closed = false;

    public function __construct(private string $data = '')
    {
    }

    public function __toString(): string
    {
        return $this->closed ? '' : $this->data;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->data = '';
        $this->pos = 0;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return $this->closed ? null : strlen($this->data);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function eof(): bool
    {
        return $this->closed || $this->pos >= strlen($this->data);
    }

    public function isSeekable(): bool
    {
        return ! $this->closed;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $size = strlen($this->data);
        $target = match ((int) $whence) {
            SEEK_CUR => $this->pos + (int) $offset,
            SEEK_END => $size + (int) $offset,
            default => (int) $offset,
        };
        if ($this->closed || $target < 0) {
            throw new \RuntimeException('tokentify: unable to seek buffered stream');
        }
        $this->pos = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('tokentify: buffered response stream is read-only');
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function read($length): string
    {
        if ($this->closed) {
            throw new \RuntimeException('tokentify: stream is closed');
        }
        $chunk = (string) substr($this->data, $this->pos, max(0, (int) $length));
        $this->pos += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        if ($this->closed) {
            throw new \RuntimeException('tokentify: stream is closed');
        }
        $rest = (string) substr($this->data, $this->pos);
        $this->pos = strlen($this->data);

        return $rest;
    }

    public function getMetadata($key = null)
    {
        $meta = ['seekable' => $this->isSeekable(), 'mode' => 'rb'];

        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
