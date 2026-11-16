<?php

declare(strict_types=1);

namespace UsageMeter;

use Tokentify\SpeechUsage;
use Tokentify\TokenUsage;
use Tokentify\Tokentify as Sdk;

/**
 * @deprecated Since 0.5.0. Use {@see \Tokentify\Tokentify}. Kept as a thin delegate so existing
 *             `UsageMeter\Meter` integrations keep working; every call goes to the new SDK.
 *
 * Note: `api_key` is no longer accepted in init(). Put TOKENTIFY_API_KEY in your `.env`.
 */
final class Meter
{
    public const DEFAULT_COLLECTOR_URL = Sdk::DEFAULT_COLLECTOR_URL;

    /** Legacy snake_case option => Tokentify::init() argument. */
    private const OPTION_MAP = [
        'bucket' => 'bucket',
        'bucket_name' => 'bucketName',
        'app_name' => 'appName',
        'environment' => 'environment',
        'load_env_file' => 'loadEnvFile',
        'collector_url' => 'collectorUrl',
        'debug' => 'debug',
        'timeout' => 'timeout',
        'verify_tls' => 'verifyTls',
        'verify_connection' => 'verifyConnection',
        'verify_api_key' => 'verifyApiKey',
        'provider_buckets' => 'providerBuckets',
        'require_bucket' => 'requireBucket',
        'tracking_fields' => 'trackingFields',
        'strict_tracking_validation' => 'strictTrackingValidation',
        'fail_on_delivery_error' => 'failOnDeliveryError',
        'batch_size' => 'batchSize',
        'auto_flush_on_batch' => 'autoFlushOnBatch',
    ];

    /**
     * @param array<string, mixed> $options Legacy snake_case options (same names as before 0.5.0)
     */
    public static function init(array $options = []): void
    {
        $args = ['loadEnvFile' => true];
        foreach ($options as $key => $value) {
            $args[self::OPTION_MAP[$key] ?? $key] = $value;
        }
        if (isset($args['timeout'])) {
            $args['timeout'] = (float) $args['timeout'];
        }
        if (isset($args['batchSize'])) {
            $args['batchSize'] = max(1, (int) $args['batchSize']);
        }
        Sdk::init(...$args);
    }

    /**
     * Set process-wide tags (kept until changed; legacy behavior).
     *
     * @param array<string, mixed> $tags
     */
    public static function tag(array $tags): void
    {
        Sdk::tag($tags);
    }

    public static function flush(): void
    {
        Sdk::flush();
    }

    /**
     * Outbound SMS as a usage event: input_tokens = segment count.
     *
     * @param array<string, mixed> $options
     */
    public static function trackSms(array $options = []): void
    {
        $segments = max(1, (int) ($options['segments'] ?? 1));
        unset($options['segments']);
        $options['provider'] = trim((string) ($options['provider'] ?? '')) ?: 'sms';
        $options['model'] = trim((string) ($options['model'] ?? '')) ?: 'outbound';
        $options['endpoint'] = is_string($options['endpoint'] ?? null) && $options['endpoint'] !== '' ? $options['endpoint'] : '/sms';
        $options['input_tokens'] = $segments;
        $options['output_tokens'] = 0;
        Sdk::trackLlm($options);
    }

    /**
     * Legacy generic track: `response_body` routes to trackFromResponse(), everything else to trackLlm().
     * For custom (non-LLM) events use {@see \Tokentify\Tokentify::track()} / {@see trackCustom()}.
     *
     * @param array<string, mixed> $options
     */
    public static function track(array $options): void
    {
        if (array_key_exists('response_body', $options) && $options['response_body'] !== null) {
            $body = $options['response_body'];
            unset($options['response_body']);
            Sdk::trackFromResponse($options, $body);

            return;
        }
        Sdk::trackLlm($options);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $vendorMetadata Keys in $options['metadata'] win on collision
     */
    public static function trackWithVendorMetadata(array $options, array $vendorMetadata): void
    {
        $fromTrack = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        $options['metadata'] = array_merge($vendorMetadata, $fromTrack);
        self::track($options);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function trackCustom(
        string $eventType,
        string $service,
        string $operation,
        int|float $units = 0,
        string $unitType = 'tokens',
        float $costUsd = 0.0,
        ?array $metadata = null,
    ): void {
        Sdk::track($eventType, $service, $operation, $units, $unitType, $costUsd, $metadata);
    }

    /** @param array<string, mixed> $options */
    public static function trackLlm(array $options): TokenUsage
    {
        return Sdk::trackLlm($options);
    }

    /** @param array<string, mixed> $options */
    public static function trackFromResponse(array $options, string $responseBody): TokenUsage
    {
        return Sdk::trackFromResponse($options, $responseBody);
    }

    /** @param array<string, mixed> $options */
    public static function trackSpeech(array $options): SpeechUsage
    {
        return Sdk::trackSpeech($options);
    }

    /** @param array<string, mixed> $options */
    public static function trackFromSpeechResponse(array $options, string $responseBody, ?string $requestBody = null): SpeechUsage
    {
        return Sdk::trackFromSpeechResponse($options, $responseBody, $requestBody);
    }

    /** @param array<string, mixed> $options */
    public static function trackTool(array $options): void
    {
        Sdk::trackTool($options);
    }

    public static function endSession(string $sessionId, float $timeout = 5.0): bool
    {
        return Sdk::endSession($sessionId, $timeout);
    }

    public static function isInitialized(): bool
    {
        return Sdk::isInitialized();
    }
}
