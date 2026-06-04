<?php

declare(strict_types=1);

namespace UsageMeter\Exception;

/**
 * Thrown for invalid or incomplete SDK setup (init options, env).
 */
final class ConfigurationException extends SdkException
{
    public static function missingApiKey(): self
    {
        return new self(
            'UsageMeter: API key required. Set USAGEMETER_API_KEY (or UM_API_KEY / USAGEMETER_TOKEN / UM_TOKEN) or pass api_key to init().',
            0,
            null,
            ['sdk_error' => 'missing_api_key'],
        );
    }

    public static function missingBucket(): self
    {
        return new self(
            'UsageMeter: bucket name required. Pass bucket to init() or set USAGEMETER_BUCKET in the environment.',
            0,
            null,
            ['sdk_error' => 'missing_bucket'],
        );
    }

    public static function invalidTrackingFields(string $reason): self
    {
        return new self(
            'UsageMeter: invalid tracking_fields — ' . $reason,
            0,
            null,
            ['sdk_error' => 'invalid_tracking_fields', 'reason' => $reason],
        );
    }

    public static function meterNotInitialized(): self
    {
        return new self(
            'UsageMeter: call Meter::init() (or Tokentify::init()) before track(), trackSms(), or flush().',
            0,
            null,
            ['sdk_error' => 'not_initialized'],
        );
    }
}
