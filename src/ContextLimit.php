<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Extract context window limits from LLM API responses into event metadata.
 */
final class ContextLimit
{
    /** @var list<string> */
    public const METADATA_CONTEXT_ALIASES = ['context_window', 'max_context_tokens'];

    /**
     * Best-effort context limit from a provider JSON response.
     *
     * @param string|array<string, mixed>|null $responseBody
     */
    public static function extractFromResponse(string|array|null $responseBody, string $contextLimitKey = 'max_tokens'): ?int
    {
        if ($responseBody === null) {
            return null;
        }
        if (is_array($responseBody)) {
            $data = $responseBody;
        } else {
            try {
                $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
        }
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }

        $key = trim($contextLimitKey) !== '' ? trim($contextLimitKey) : 'max_tokens';
        $direct = self::parsePositiveInt($data[$key] ?? null);
        if ($direct !== null && $direct >= 1000) {
            return $direct;
        }
        foreach (self::METADATA_CONTEXT_ALIASES as $alias) {
            $alt = self::parsePositiveInt($data[$alias] ?? null);
            if ($alt !== null && $alt >= 1000) {
                return $alt;
            }
        }

        return self::walk($data);
    }

    public static function parsePositiveInt(mixed $value): ?int
    {
        if (is_bool($value)) {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value) && floor($value) === $value && $value > 0) {
            return (int) $value;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            $v = (int) trim($value);

            return $v > 0 ? $v : null;
        }

        return null;
    }

    private static function walk(mixed $obj, int $depth = 0): ?int
    {
        if ($depth > 6 || ! is_array($obj)) {
            return null;
        }
        if (array_is_list($obj)) {
            foreach (array_slice($obj, 0, 8) as $item) {
                $found = self::walk($item, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }
        foreach (['max_tokens', 'context_window', 'max_context_tokens', 'context_length'] as $key) {
            if (array_key_exists($key, $obj)) {
                $parsed = self::parsePositiveInt($obj[$key]);
                if ($parsed !== null && $parsed >= 1000) {
                    return $parsed;
                }
            }
        }
        foreach ($obj as $value) {
            $found = self::walk($value, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
