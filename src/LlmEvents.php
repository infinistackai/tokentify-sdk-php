<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Build and resolve LLM usage events for ingest.
 */
final class LlmEvents
{
    public const SDK_VERSION = '0.5.0';

    /** SDK endpoint names => the path the gateway reports (bucket routing rules on `endpoint` use this form). */
    public const WIRE_ENDPOINTS = [
        'chat' => '/v1/chat/completions',
        'completions' => '/v1/completions',
        'responses' => '/v1/responses',
        'messages' => '/v1/messages',
        'embed' => '/v1/embeddings',
        'audio/transcriptions' => '/v1/audio/transcriptions',
        'audio/translations' => '/v1/audio/translations',
        'audio/speech' => '/v1/audio/speech',
    ];

    /** The collector-facing endpoint for an SDK endpoint name; anything else passes through unchanged. */
    public static function wireEndpoint(mixed $endpoint): mixed
    {
        return is_string($endpoint) && isset(self::WIRE_ENDPOINTS[$endpoint]) ? self::WIRE_ENDPOINTS[$endpoint] : $endpoint;
    }

    /**
     * Merge explicit counts, alias keys, and parsed response usage.
     *
     * @param array<string, mixed> $options
     */
    public static function resolveTokenUsage(
        array $options,
        ?string $responseBody = null,
        ?string $provider = null,
    ): TokenUsage {
        $parsed = new TokenUsage();
        if ($responseBody !== null && $provider !== null && $provider !== '') {
            $parsed = UsageParser::fromProviderResponse($responseBody, $provider);
        }

        $pick = static function (array $keys, int $fallback) use ($options): int {
            foreach ($keys as $key) {
                if (array_key_exists($key, $options) && $options[$key] !== null) {
                    return (int) $options[$key];
                }
            }

            return $fallback;
        };

        $providerKey = UsageParser::normalizeProvider($provider ?? (string) ($options['provider'] ?? ''));
        $usage = new TokenUsage(
            $pick(['input_tokens', 'prompt_tokens'], $parsed->inputTokens),
            $pick(['output_tokens', 'completion_tokens'], $parsed->outputTokens),
            $pick(['cache_read_tokens', 'cache_read_input_tokens'], $parsed->cacheReadTokens),
            $pick(['cache_write_tokens', 'cache_creation_input_tokens'], $parsed->cacheWriteTokens),
        );

        // TokenUsage holds input_tokens WITHOUT the cached part. Parsed responses already come that way;
        // an explicit OpenAI-family input_tokens / prompt_tokens is the provider's raw count (cached included),
        // so only that one is reduced here.
        $explicitInput = ($options['input_tokens'] ?? null) !== null || ($options['prompt_tokens'] ?? null) !== null;
        if (! $explicitInput) {
            return $usage;
        }

        return UsageParser::normalizeOpenaiFamilyUsage($usage, $providerKey);
    }

    /**
     * `input_tokens` as the collector expects it, the same as the gateway sends it: OpenAI-family counts
     * include the cached tokens (the provider's raw `prompt_tokens`); the collector removes them once when
     * pricing. Other providers (Anthropic, Google, ...) report input without the cache, unchanged.
     */
    public static function wireInputTokens(TokenUsage $usage, string $provider): int
    {
        if (UsageParser::isOpenaiFamily($provider)) {
            return $usage->inputTokens + $usage->cacheReadTokens;
        }

        return $usage->inputTokens;
    }

    /**
     * Build a collector ingest event from resolved usage.
     *
     * @param array<string, mixed> $options
     * @param callable(): array<string, mixed> $getTagsFn
     * @return array<string, mixed>
     */
    public static function buildLlmIngestEvent(
        array $options,
        TokenUsage $usage,
        callable $getTagsFn,
        string $sdkVersion = self::SDK_VERSION,
    ): array {
        $tags = $getTagsFn();
        foreach ((is_array($options['metadata'] ?? null) ? $options['metadata'] : []) as $k => $v) {
            $tags[(string) $k] = $v;
        }
        Attribution::applyCallAttribution($tags, $options);

        $inputTokens = self::wireInputTokens($usage, (string) ($options['provider'] ?? ''));

        // Mirror token counts into metadata for custom-metrics field discovery (same values as the gateway).
        $tags['input_tokens'] = $inputTokens;
        $tags['output_tokens'] = $usage->outputTokens;
        $tags['total_tokens'] = $inputTokens + $usage->outputTokens;
        $tags['cache_read_tokens'] = $usage->cacheReadTokens;
        $tags['cache_write_tokens'] = $usage->cacheWriteTokens;
        if (($options['endpoint'] ?? null) !== null) {
            $tags['endpoint'] = self::wireEndpoint($options['endpoint']);
        }
        $provider = UsageParser::normalizeProvider((string) ($options['provider'] ?? ''));
        if ($provider !== '') {
            $tags['provider'] = $provider;
        }

        $bucketName = self::pull($tags, 'bucket_name');
        $bucketSlug = self::pull($tags, 'bucket_slug') ?? ($options['bucket_slug'] ?? null);
        $appName = self::pull($tags, 'app_name');

        $event = [
            'event_id' => self::eventId($options),
            'timestamp' => self::formatTimestamp($options['timestamp'] ?? null),
            'provider' => $provider,
            'model' => (string) (($options['model'] ?? '') ?: 'unknown'),
            'input_tokens' => $inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'cache_write_tokens' => $usage->cacheWriteTokens,
            'status' => (string) (($options['status'] ?? '') ?: 'success'),
            'sdk_version' => $sdkVersion,
            'metadata' => $tags,
        ];

        // As on the gateway: "reported" when the provider (or the caller) gave counts, "none" when there were none.
        $hasUsage = $inputTokens > 0 || $usage->outputTokens > 0 || $usage->cacheReadTokens > 0 || $usage->cacheWriteTokens > 0;
        $event['usage_source'] = (string) (($options['usage_source'] ?? '') ?: ($hasUsage ? 'reported' : 'none'));
        if (($options['endpoint'] ?? null) !== null) {
            $event['endpoint'] = self::wireEndpoint($options['endpoint']);
        }
        if (($options['latency_ms'] ?? null) !== null) {
            $event['latency_ms'] = (int) $options['latency_ms'];
        }
        if (($options['http_status'] ?? null) !== null) {
            $event['http_status'] = (int) $options['http_status'];
        }
        if (($options['error_code'] ?? null) !== null) {
            $event['error_code'] = $options['error_code'];
        }
        if ($appName !== null && $appName !== '') {
            $event['app_name'] = (string) $appName;
        }
        if ($bucketName !== null && $bucketName !== '') {
            $event['bucket_name'] = (string) $bucketName;
        }
        if ($bucketSlug !== null && $bucketSlug !== '') {
            $event['bucket_slug'] = (string) $bucketSlug;
        }

        return $event;
    }

    /**
     * Remove and return $tags[$key] (Python's dict.pop(key, None)).
     *
     * @param array<string, mixed> $tags
     */
    public static function pull(array &$tags, string $key): mixed
    {
        if (! array_key_exists($key, $tags)) {
            return null;
        }
        $value = $tags[$key];
        unset($tags[$key]);

        return $value;
    }

    public static function formatTimestamp(mixed $timestamp): string
    {
        if (is_int($timestamp) || is_float($timestamp)) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
        }
        if (is_string($timestamp) && trim($timestamp) !== '') {
            return trim($timestamp);
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function eventId(array $options): string
    {
        $given = $options['event_id'] ?? null;

        return is_scalar($given) && (string) $given !== '' ? (string) $given : self::uuidV4();
    }

    public static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
