<?php

declare(strict_types=1);

namespace Tokentify\Exception;

/**
 * Thrown for invalid or incomplete SDK setup (setup / init options, env).
 */
final class ConfigurationException extends SdkException
{
    public const SETUP_HINT = 'Add TOKENTIFY_API_KEY to your .env file, then call Tokentify::loadEnv() and Tokentify::setup(). '
        . "Set TOKENTIFY_BUCKET in .env or pass Tokentify::setup('your-bucket').";

    public static function missingApiKey(): self
    {
        return new self(
            'tokentify: API key required. '
            . 'Add TOKENTIFY_API_KEY to your .env file, then call Tokentify::loadEnv() and Tokentify::setup().',
            0,
            null,
            ['sdk_error' => 'missing_api_key'],
        );
    }

    public static function apiKeyNotAllowed(): self
    {
        return new self(
            'tokentify: api_key cannot be passed to init() or setup(). '
            . 'Set TOKENTIFY_API_KEY in your .env file (see .env.example).',
            0,
            null,
            ['sdk_error' => 'api_key_argument'],
        );
    }

    public static function missingBucket(): self
    {
        return new self(
            'tokentify: bucket name required. '
            . "Add TOKENTIFY_BUCKET to your .env or pass Tokentify::setup('your-bucket'). " . self::SETUP_HINT,
            0,
            null,
            ['sdk_error' => 'missing_bucket'],
        );
    }

    public static function unknownOption(string $name): self
    {
        return new self(
            "tokentify: unknown option '{$name}' passed to init() or setup().",
            0,
            null,
            ['sdk_error' => 'unknown_option', 'option' => $name],
        );
    }

    public static function invalidTrackingFields(string $reason): self
    {
        return new self(
            'tokentify: invalid tracking_fields: ' . $reason,
            0,
            null,
            ['sdk_error' => 'invalid_tracking_fields', 'reason' => $reason],
        );
    }

    public static function notInitialized(string $action): self
    {
        return new self(
            "tokentify: call init() or setup() before {$action}. " . self::SETUP_HINT,
            0,
            null,
            ['sdk_error' => 'not_initialized', 'action' => $action],
        );
    }

    /** @deprecated Use notInitialized(). Kept for UsageMeter\Meter callers. */
    public static function meterNotInitialized(): self
    {
        return self::notInitialized('track(), trackSms(), or flush()');
    }
}
