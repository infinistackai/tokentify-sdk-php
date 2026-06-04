<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Maps Tokentify-style tracking field names onto flat group_key_N / group_value_N pairs
 * inside the collector's JSON metadata (analytics-friendly denormalized shape).
 */
final class TrackingMetadata
{
    private const MAX_SLOTS = 20;

    /**
     * @param list<string> $trackingFields Ordered keys, e.g. ['account_id', 'user_id'] → group_1, group_2.
     * @param array<string, mixed> $metadata Merged metadata (already includes tags and per-call metadata).
     *
     * @return array<string, mixed> Metadata with group_key_i / group_value_i added when the key exists.
     */
    public static function applyFlatGroups(array $metadata, array $trackingFields): array
    {
        if ($trackingFields === []) {
            return $metadata;
        }
        $slot = 1;
        foreach ($trackingFields as $field) {
            if ($slot > self::MAX_SLOTS) {
                break;
            }
            $key = trim((string) $field);
            if ($key === '') {
                continue;
            }
            $metadata['group_key_' . $slot] = $key;
            $metadata['group_value_' . $slot] = array_key_exists($key, $metadata)
                ? self::scalarToString($metadata[$key])
                : '';
            $slot++;
        }

        return $metadata;
    }

    /**
     * @param list<string> $trackingFields
     * @return list<string> Keys that are absent from $metadata
     */
    public static function missingKeys(array $metadata, array $trackingFields): array
    {
        $missing = [];
        foreach ($trackingFields as $field) {
            $key = trim((string) $field);
            if ($key === '') {
                continue;
            }
            if (! array_key_exists($key, $metadata)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private static function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
