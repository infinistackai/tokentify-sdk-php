<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Build and resolve speech usage events for ingest.
 */
final class SpeechEvents
{
    /**
     * @param array<string, mixed> $options
     */
    public static function resolveSpeechUsage(
        array $options,
        ?string $responseBody = null,
        ?string $requestBody = null,
        ?string $provider = null,
    ): SpeechUsage {
        $explicitKeys = ['billable_quantity', 'billable_unit', 'kind', 'metering_kind', 'canonical_quantity'];
        foreach ($explicitKeys as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $billableUnit = (string) ($options['billable_unit'] ?? $options['canonical_unit'] ?? 'seconds');
                $meteringKind = $options['metering_kind'] ?? null;
                if ($meteringKind === 'api_request') {
                    $billableUnit = 'request';
                } elseif ($meteringKind === 'tts_characters') {
                    $billableUnit = 'characters';
                } elseif ($meteringKind === 'stt_words') {
                    $billableUnit = 'words';
                } elseif ($meteringKind === 'stt_duration') {
                    $billableUnit = 'seconds';
                }
                $qty = $options['canonical_quantity'] ?? $options['billable_quantity'] ?? 0;

                return new SpeechUsage(
                    (string) ($options['kind'] ?? 'stt'),
                    $billableUnit,
                    (float) $qty,
                    (int) ($options['input_tokens'] ?? 0),
                    (int) ($options['output_tokens'] ?? 0),
                    (bool) ($options['metering_gap'] ?? false),
                );
            }
        }

        if ($responseBody !== null) {
            $opts = $options;
            if ($provider !== null && $provider !== '') {
                $opts['provider'] = $provider;
            }

            return SpeechParser::fromProviderResponse($opts, $responseBody, $requestBody);
        }

        return new SpeechUsage(
            (string) ($options['kind'] ?? 'stt'),
            (string) ($options['billable_unit'] ?? 'seconds'),
            (float) ($options['billable_quantity'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param callable(): array<string, mixed> $getTagsFn
     * @return array<string, mixed>
     */
    public static function buildSpeechIngestEvent(
        array $options,
        SpeechUsage $usage,
        callable $getTagsFn,
        string $sdkVersion = LlmEvents::SDK_VERSION,
    ): array {
        $tags = $getTagsFn();
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        foreach ($metadata as $k => $v) {
            $tags[(string) $k] = $v;
        }
        foreach ($usage->toMetadata() as $k => $v) {
            $tags[(string) $k] = $v;
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

        if (! is_string($bucketName) || $bucketName === '') {
            $bucketName = $usage->defaultBucketName();
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
            'provider' => UsageParser::normalizeProvider((string) ($options['provider'] ?? '')),
            'model' => (string) ($options['model'] ?? 'unknown'),
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'status' => (string) ($options['status'] ?? 'success'),
            'sdk_version' => $sdkVersion,
            'metadata' => $tags !== [] ? $tags : [],
            'bucket_name' => $bucketName,
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
        $requestContentType = $options['request_content_type'] ?? null;
        if (is_string($requestContentType) && $requestContentType !== '') {
            if (! is_array($event['metadata'])) {
                $event['metadata'] = [];
            }
            $event['metadata']['request_content_type'] = $requestContentType;
        }
        if (is_string($appName) && $appName !== '') {
            $event['app_name'] = $appName;
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
