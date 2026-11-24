<?php

declare(strict_types=1);

namespace Tokentify\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Tokentify\Attribution;
use Tokentify\LlmEvents;
use Tokentify\SpeechEvents;
use Tokentify\SpeechParser;
use Tokentify\SpeechUsage;
use Tokentify\TokenUsage;
use Tokentify\ToolCalls;
use Tokentify\UsageParser;

/**
 * Shared request / response metering used by {@see MeteringMiddleware} (Guzzle) and
 * {@see MeteredHttpClient} (PSR-18). PHP's counterpart of the Python SDK's httpx MeteringTransport.
 *
 * - Requests to known AI hosts lose their `X-Tokentify-*` headers (attribution comes from tags).
 * - Non-streaming responses are read, parsed and emitted; the body is handed back intact.
 * - `text/event-stream` responses are wrapped in a {@see MeteringStream} that emits when the stream ends.
 * - Nothing here ever throws into the caller's request.
 */
final class Transport
{
    /** @var array<string, string> Host substring => provider */
    public const AI_HOSTS = [
        'api.anthropic.com' => 'anthropic',
        'api.openai.com' => 'openai',
        'generativelanguage.googleapis.com' => 'google',
        'api.cohere.com' => 'cohere',
        'api.mistral.ai' => 'mistral',
        'api.groq.com' => 'groq',
        'api.perplexity.ai' => 'perplexity',
        'api.fireworks.ai' => 'fireworks',
        'openrouter.ai' => 'openrouter',
    ];

    private const MAX_ERROR_CODE = 64;

    /** Providers that put the model in the URL path (Gemini / Vertex, Bedrock runtime). */
    private const PATH_MODEL_PATTERNS = [
        '#/models/([^/:?]+):[A-Za-z]+#',
        '#/model/([^/?]+)/(?:converse|invoke)#',
    ];

    /**
     * @param \Closure(array<string, mixed>): void $emit Enqueue one ingest event
     * @param \Closure(): array<string, mixed> $getTags Current process tags
     * @param \Closure(string, array<string, mixed>): array<string, mixed> $resolveBucket Apply provider_buckets
     * @param \Closure(): bool $ready Whether the SDK is set up (may auto-enable)
     */
    public function __construct(
        private readonly \Closure $emit,
        private readonly \Closure $getTags,
        private readonly \Closure $resolveBucket,
        private readonly \Closure $ready,
    ) {
    }

    /**
     * Prepare an outgoing request. Returns the (possibly header-stripped) request and a metering
     * context, or null as the context when the call should not be metered.
     *
     * @return array{0: RequestInterface, 1: array<string, mixed>|null}
     */
    public function beforeRequest(RequestInterface $request): array
    {
        try {
            $host = $request->getUri()->getHost();
            if (! self::isAiRequest($host)) {
                return [$request, null];
            }
            // Never let X-Tokentify-* reach a provider. Other hosts (the gateway, which reads them) keep them.
            foreach (Attribution::tokentifyHeaderNames($request->getHeaders()) as $name) {
                $request = $request->withoutHeader($name);
            }
            if (! ($this->ready)()) {
                return [$request, null];
            }

            $path = $request->getUri()->getPath();
            $body = self::readRequestBody($request);
            // Snapshot tags now: the response may be consumed after a tag scope has been restored.
            $tags = $this->snapshotTags();
            // The request body's OpenAI `user` field is the end user (as on the gateway); per call, so it beats tag().
            $bodyUser = Attribution::bodyEndUser($body);
            if ($bodyUser !== '') {
                $tags['user_id'] = $bodyUser;
            }

            return [$request, [
                'method' => strtoupper($request->getMethod()),
                'start' => hrtime(true),
                'timestamp' => microtime(true),
                'provider' => self::detectProvider($host),
                'endpoint' => self::detectEndpoint($path),
                'model' => self::detectModel($body, $path),
                'request_body' => $body,
                'request_content_type' => $request->getHeaderLine('Content-Type') ?: null,
                'tags' => $tags,
            ]];
        } catch (\Throwable) {
            return [$request, null];
        }
    }

    /**
     * Meter a response. Always returns a usable response (the original, or one with an equivalent body).
     *
     * @param array<string, mixed> $ctx From {@see beforeRequest()}
     */
    public function afterResponse(array $ctx, ResponseInterface $response): ResponseInterface
    {
        try {
            $latencyMs = self::elapsedMs($ctx['start']);
            $provider = (string) $ctx['provider'];
            $endpoint = (string) $ctx['endpoint'];
            $httpStatus = $response->getStatusCode();
            $status = $httpStatus >= 200 && $httpStatus < 300 ? 'success' : 'error';

            if (str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream')) {
                $start = $ctx['start'];
                $onComplete = function (array $usage) use ($ctx, $start): void {
                    $this->emitLlm($ctx, $usage, self::elapsedMs($start));
                };

                return $response->withBody(new MeteringStream($response->getBody(), $provider, $onComplete));
            }

            [$response, $content] = self::readResponseBody($response);

            if (SpeechParser::isSpeechEndpoint($endpoint)) {
                $this->maybeEmitSpeech($ctx, $content, $latencyMs, $httpStatus, $status);
            } else {
                $usage = self::extractUsage($content, $provider);
                if ($usage !== []) {
                    $ctx['tags'] = ToolCalls::addToolCallTags($ctx['tags'], $content);
                    $this->emitLlm($ctx, $usage, $latencyMs, $httpStatus, $status);
                } elseif ($status === 'error' && self::recordsFailures($ctx)) {
                    // A failed LLM call has no usage: record it with 0 tokens and the provider's error code.
                    $this->emitLlm($ctx, [], $latencyMs, $httpStatus, $status, self::errorCode($content, $httpStatus));
                }
            }

            return $response;
        } catch (\Throwable) {
            return $response;
        }
    }

    /**
     * Record a call that failed without a response (connection error, timeout): 0 tokens, status `error`,
     * no `http_status` (as the gateway does). The caller rethrows the original exception.
     *
     * @param array<string, mixed> $ctx From {@see beforeRequest()}
     */
    public function onFailure(array $ctx, \Throwable $error): void
    {
        try {
            if (! self::recordsFailures($ctx)) {
                return;
            }
            $name = (new \ReflectionClass($error))->getShortName();
            $this->emitLlm($ctx, [], self::elapsedMs($ctx['start']), null, 'error', substr($name, 0, self::MAX_ERROR_CODE));
        } catch (\Throwable) {
        }
    }

    /**
     * An error response whose body must not be read (it can't be rewound and the caller still needs it):
     * recorded as a failed call with `error_code` `http_<status>`.
     */
    public function onErrorStatus(array $ctx, int $status): void
    {
        try {
            if (! self::recordsFailures($ctx)) {
                return;
            }
            $this->emitLlm($ctx, [], self::elapsedMs($ctx['start']), $status, 'error', self::errorCode(null, $status));
        } catch (\Throwable) {
        }
    }

    /**
     * A short error code for a failed AI call, from the provider's error body: OpenAI `error.code` (else
     * `error.type`), Anthropic `error.type`, Gemini `error.status`; else `http_<status>`. Never the error
     * message, which can echo prompt content.
     */
    public static function errorCode(?string $body, ?int $httpStatus = null): string
    {
        try {
            $data = $body !== null && $body !== '' ? json_decode($body, true) : null;
            $err = is_array($data) ? ($data['error'] ?? null) : null;
            if (is_array($err)) {
                foreach (['code', 'type', 'status'] as $key) {
                    $value = $err[$key] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        return substr(trim($value), 0, self::MAX_ERROR_CODE);
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $httpStatus ? 'http_' . $httpStatus : 'error';
    }

    // ── Detection helpers (public for tests and custom transports) ───

    public static function isAiRequest(string $host): bool
    {
        return self::detectProvider($host) !== 'unknown';
    }

    public static function detectProvider(string $host): string
    {
        $host = strtolower($host);
        if ($host === '') {
            return 'unknown';
        }
        foreach (self::AI_HOSTS as $aiHost => $provider) {
            if (str_contains($host, $aiHost)) {
                return $provider;
            }
        }
        if (str_ends_with($host, '.openai.azure.com')) {
            return 'azure';
        }
        if (str_contains($host, 'bedrock') && str_contains($host, 'amazonaws.com')) {
            return 'bedrock';
        }

        return 'unknown';
    }

    public static function detectEndpoint(string $path): string
    {
        $p = strtolower($path);

        return match (true) {
            $p === '' => 'unknown',
            // OpenAI Assistants (threads, runs, thread messages) are not model calls.
            str_contains($p, '/threads/') || str_ends_with(rtrim($p, '/'), '/threads') => 'unknown',
            str_contains($p, '/messages') => 'messages',
            str_contains($p, '/chat/completions') => 'chat',
            str_contains($p, '/completions') => 'completions',
            str_ends_with(rtrim($p, '/'), '/responses') => 'responses',
            str_contains($p, 'generatecontent') => 'generateContent',
            str_contains($p, '/generate') => 'generate',
            str_contains($p, '/embed') => 'embed',
            str_contains($p, '/audio/transcription') => 'audio/transcriptions',
            str_contains($p, '/audio/translation') => 'audio/translations',
            str_contains($p, '/audio/speech') => 'audio/speech',
            default => 'unknown',
        };
    }

    /** Model from a provider URL path (Gemini `/models/x:generateContent`, Bedrock `/model/x/converse`), or `unknown`. */
    public static function modelFromPath(string $path): string
    {
        foreach (self::PATH_MODEL_PATTERNS as $pattern) {
            if (preg_match($pattern, $path, $m) === 1) {
                $model = trim(rawurldecode($m[1]));
                if ($model !== '') {
                    return $model;
                }
            }
        }

        return 'unknown';
    }

    /** Model from the request body (JSON or multipart), else from the URL path. */
    public static function detectModel(?string $body, string $path = ''): string
    {
        $model = SpeechParser::detectModel($body);
        if ($model === 'unknown' && $path !== '') {
            $model = self::modelFromPath($path);
        }

        return $model;
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $ctx
     */
    private function maybeEmitSpeech(array $ctx, string $content, int $latencyMs, int $httpStatus, string $status): void
    {
        $endpoint = (string) $ctx['endpoint'];
        $provider = (string) $ctx['provider'];
        $isStt = SpeechParser::isSttEndpoint($endpoint);

        if ($status === 'error') {
            $speech = $isStt
                ? new SpeechUsage('stt', 'seconds', 0.0)
                : new SpeechUsage('tts', 'characters', 0.0);
            $this->emitSpeech($ctx, $speech, $latencyMs, $httpStatus, $status);

            return;
        }

        $speech = SpeechParser::fromTransport(
            $endpoint,
            $provider,
            $isStt ? $content : null,
            $ctx['request_body'],
        );
        if ($speech !== null && $speech->shouldEmit()) {
            if ($speech->billableUnit === 'tokens') {
                $this->emitLlm(
                    $ctx,
                    ['input_tokens' => $speech->inputTokens, 'output_tokens' => $speech->outputTokens],
                    $latencyMs,
                    $httpStatus,
                    $status,
                );

                return;
            }
            $this->emitSpeech($ctx, $speech, $latencyMs, $httpStatus, $status);

            return;
        }

        if ($isStt) {
            $usage = self::extractUsage($content, $provider);
            if ($usage !== []) {
                $this->emitLlm($ctx, $usage, $latencyMs, $httpStatus, $status);
            }
        }
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, int> $usage
     */
    private function emitLlm(
        array $ctx,
        array $usage,
        int $latencyMs,
        ?int $httpStatus = 200,
        string $status = 'success',
        ?string $errorCode = null,
    ): void {
        try {
            $event = LlmEvents::buildLlmIngestEvent(
                [
                    'provider' => $ctx['provider'],
                    'endpoint' => $ctx['endpoint'],
                    'model' => $ctx['model'],
                    'timestamp' => $ctx['timestamp'],
                    'latency_ms' => $latencyMs,
                    'http_status' => $httpStatus,
                    'status' => $status,
                    'error_code' => $errorCode,
                ],
                TokenUsage::fromInternalUsage($usage),
                $this->tagsGetter($ctx),
                LlmEvents::SDK_VERSION,
            );
            ($this->emit)($event);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function emitSpeech(array $ctx, SpeechUsage $speech, int $latencyMs, int $httpStatus, string $status): void
    {
        try {
            $event = SpeechEvents::buildSpeechIngestEvent(
                [
                    'provider' => $ctx['provider'],
                    'endpoint' => $ctx['endpoint'],
                    'model' => $ctx['model'],
                    'timestamp' => $ctx['timestamp'],
                    'latency_ms' => $latencyMs,
                    'http_status' => $httpStatus,
                    'status' => $status,
                    'request_content_type' => $ctx['request_content_type'],
                ],
                $speech,
                $this->tagsGetter($ctx),
                LlmEvents::SDK_VERSION,
            );
            ($this->emit)($event);
        } catch (\Throwable) {
        }
    }

    /**
     * Failed calls are recorded only for LLM endpoints (POST), never for model lists, files, etc.
     *
     * @param array<string, mixed> $ctx
     */
    private static function recordsFailures(array $ctx): bool
    {
        return ($ctx['endpoint'] ?? 'unknown') !== 'unknown' && ($ctx['method'] ?? 'POST') === 'POST';
    }

    /**
     * @param array<string, mixed> $ctx
     * @return \Closure(): array<string, mixed>
     */
    private function tagsGetter(array $ctx): \Closure
    {
        $snapshot = is_array($ctx['tags'] ?? null) ? $ctx['tags'] : $this->snapshotTags();
        try {
            $snapshot = ($this->resolveBucket)((string) $ctx['provider'], $snapshot);
        } catch (\Throwable) {
        }

        return static fn (): array => $snapshot;
    }

    /** @return array<string, mixed> */
    private function snapshotTags(): array
    {
        try {
            return ($this->getTags)();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Internal usage array, or [] when the body carries no token usage.
     *
     * @return array<string, int>
     */
    private static function extractUsage(string $body, string $provider): array
    {
        $usage = UsageParser::fromProviderResponse($body, $provider);
        if ($usage->totalTokens() === 0 && $usage->cacheTokens() === 0) {
            return [];
        }
        $out = $usage->toArray();
        $out['cache_read_input_tokens'] = $usage->cacheReadTokens;
        $out['cache_creation_input_tokens'] = $usage->cacheWriteTokens;

        return $out;
    }

    /** Request body without consuming it: only read when the stream can be rewound. */
    private static function readRequestBody(RequestInterface $request): ?string
    {
        try {
            $stream = $request->getBody();
            if (! $stream->isSeekable()) {
                return null;
            }
            $stream->rewind();
            $body = $stream->getContents();
            $stream->rewind();

            return $body === '' ? null : $body;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the full response body and return a response whose body is unread from the start.
     *
     * @return array{0: ResponseInterface, 1: string}
     */
    private static function readResponseBody(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $pos = self::tell($stream);
            $stream->rewind();
            $content = $stream->getContents();
            $stream->seek($pos);

            return [$response, $content];
        }
        $content = $stream->getContents();

        return [$response->withBody(new BufferStream($content)), $content];
    }

    private static function tell(StreamInterface $stream): int
    {
        try {
            return $stream->tell();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function elapsedMs(int|float $start): int
    {
        return max(0, (int) ((hrtime(true) - $start) / 1_000_000));
    }
}
