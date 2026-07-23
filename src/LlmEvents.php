<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Build and resolve LLM usage events for ingest.
 */
final class LlmEvents
{
    public const SDK_VERSION = '0.3.3';

    /**
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

        $pick = static function (array $opts, array $keys, int $fallback): int {
            foreach ($keys as $key) {
                if (array_key_exists($key, $opts) && $opts[$key] !== null) {
                    return (int) $opts[$key];
                }
            }

            return $fallback;
        };

        $providerKey = UsageParser::normalizeProvider($provider ?? (string) ($options['provider'] ?? ''));
        $usage = new TokenUsage(
            $pick($options, ['input_tokens', 'prompt_tokens'], $parsed->inputTokens),
            $pick($options, ['output_tokens', 'completion_tokens'], $parsed->outputTokens),
            $pick($options, ['cache_read_tokens', 'cache_read_input_tokens'], $parsed->cacheReadTokens),
            $pick($options, ['cache_write_tokens', 'cache_creation_input_tokens'], $parsed->cacheWriteTokens),
        );

        return UsageParser::normalizeOpenaiFamilyUsage($usage, $providerKey);
    }

    /**
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
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        foreach ($metadata as $k => $v) {
            $tags[(string) $k] = $v;
        }

        $tags['input_tokens'] = $usage->inputTokens;
        $tags['output_tokens'] = $usage->outputTokens;
        $tags['total_tokens'] = $usage->inputTokens + $usage->outputTokens;
        $tags['cache_read_tokens'] = $usage->cacheReadTokens;
        $tags['cache_write_tokens'] = $usage->cacheWriteTokens;
        if (array_key_exists('endpoint', $options) && $options['endpoint'] !== null) {
            $tags['endpoint'] = $options['endpoint'];
        }
        $provider = UsageParser::normalizeProvider((string) ($options['provider'] ?? ''));
        if ($provider !== '') {
            $tags['provider'] = $provider;
        }

        $bucketName = null;
        if (array_key_exists('bucket_name', $tags)) {
            $bucketName = $tags['bucket_name'];
            unset($tags['bucket_name']);
        }
        $appName = null;
        if (array_key_exists('app_name', $tags)) {
            $appName = $tags['app_name'];
            unset($tags['app_name']);
        }

        $timestamp = $options['timestamp'] ?? null;
        if (is_int($timestamp) || is_float($timestamp)) {
            $tsStr = gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
        } elseif (is_string($timestamp) && $timestamp !== '') {
            $tsStr = $timestamp;
        } else {
            $tsStr = gmdate('Y-m-d\TH:i:s\Z');
        }

        $eventId = isset($options['event_id']) && is_string($options['event_id']) && $options['event_id'] !== ''
            ? $options['event_id']
            : self::uuidV4();

        $event = [
            'event_id' => $eventId,
            'timestamp' => $tsStr,
            'provider' => $provider,
            'model' => (string) ($options['model'] ?? 'unknown'),
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'cache_write_tokens' => $usage->cacheWriteTokens,
            'status' => (string) ($options['status'] ?? 'success'),
            'sdk_version' => $sdkVersion,
            'metadata' => $tags !== [] ? $tags : [],
        ];

        if (array_key_exists('endpoint', $options) && $options['endpoint'] !== null) {
            $event['endpoint'] = $options['endpoint'];
        }
        if (array_key_exists('latency_ms', $options) && $options['latency_ms'] !== null) {
            $event['latency_ms'] = (int) $options['latency_ms'];
        }
        if (array_key_exists('http_status', $options) && $options['http_status'] !== null) {
            $event['http_status'] = (int) $options['http_status'];
        }
        if (array_key_exists('error_code', $options) && $options['error_code'] !== null) {
            $event['error_code'] = $options['error_code'];
        }
        if (is_string($appName) && $appName !== '') {
            $event['app_name'] = $appName;
        }
        if (is_string($bucketName) && $bucketName !== '') {
            $event['bucket_name'] = $bucketName;
        }

        return $event;
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
