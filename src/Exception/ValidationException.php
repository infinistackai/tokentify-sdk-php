<?php

declare(strict_types=1);

namespace Tokentify\Exception;

/**
 * Thrown when strict Tokentify-style metadata validation fails (missing tracking keys)
 * or when track() options are incomplete.
 */
final class ValidationException extends SdkException
{
    public static function missingProviderOrModel(): self
    {
        return new self(
            'tokentify: track() requires non-empty provider and model keys in the options array.',
            0,
            null,
            ['sdk_error' => 'invalid_track_options'],
        );
    }

    /**
     * @param list<string> $missingKeys
     * @param array<string, mixed> $extraContext
     */
    public static function missingTrackingMetadata(array $missingKeys, array $extraContext = []): self
    {
        $keys = implode(', ', $missingKeys);

        return new self(
            'tokentify: strict_tracking_validation is enabled but metadata is missing required keys: ' . $keys
            . '. Pass them via Tokentify::tag() or the event metadata.',
            0,
            null,
            array_merge([
                'sdk_error' => 'missing_tracking_metadata',
                'missing_keys' => $missingKeys,
            ], $extraContext),
        );
    }
}
