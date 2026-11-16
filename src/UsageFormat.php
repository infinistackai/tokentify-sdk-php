<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Where one provider API puts its token counts.
 *
 * `containers`: paths to the usage object (first one found wins).
 * `input` / `output` / `cacheRead` / `cacheWrite`: candidate fields (dotted paths), first present wins.
 * `inputIncludesCache`: the provider's input count already contains the cache-read tokens.
 */
final class UsageFormat
{
    /**
     * @param list<list<string>> $containers
     * @param list<string> $input
     * @param list<string> $output
     * @param list<string> $cacheRead
     * @param list<string> $cacheWrite
     */
    public function __construct(
        public readonly array $containers,
        public readonly array $input,
        public readonly array $output,
        public readonly array $cacheRead = [],
        public readonly array $cacheWrite = [],
        public readonly bool $inputIncludesCache = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function parse(array $data): ?TokenUsage
    {
        $usage = null;
        foreach ($this->containers as $path) {
            $candidate = self::dig($data, $path);
            if (is_array($candidate)) {
                $usage = $candidate;
                break;
            }
        }
        if ($usage === null) {
            return null;
        }
        $found = [
            self::firstInt($usage, $this->input),
            self::firstInt($usage, $this->output),
            self::firstInt($usage, $this->cacheRead),
            self::firstInt($usage, $this->cacheWrite),
        ];
        if ($found === [null, null, null, null]) {
            return null;
        }
        [$inp, $out, $cacheRead, $cacheWrite] = array_map(static fn (?int $v): int => $v ?? 0, $found);
        if ($this->inputIncludesCache) {
            $inp = max(0, $inp - $cacheRead);
        }

        // TokenUsage always holds input WITHOUT the cache; LlmEvents::wireInputTokens re-adds it for the
        // providers the collector expects it on (OPENAI_FAMILY).
        return new TokenUsage($inp, $out, $cacheRead, $cacheWrite);
    }

    /**
     * @param list<string> $path
     */
    public static function dig(mixed $obj, array $path): mixed
    {
        foreach ($path as $key) {
            if (! is_array($obj) || ! array_key_exists($key, $obj)) {
                return null;
            }
            $obj = $obj[$key];
        }

        return $obj;
    }

    /**
     * First present numeric field (dotted path) in $usage; null when none is present.
     *
     * @param array<string, mixed> $usage
     * @param list<string> $fields
     */
    private static function firstInt(array $usage, array $fields): ?int
    {
        foreach ($fields as $field) {
            $value = self::dig($usage, explode('.', $field));
            if ($value === null || is_bool($value)) {
                continue;
            }
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return max(0, (int) $value);
            }
        }

        return null;
    }
}
