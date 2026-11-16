<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Tool-call names from a provider's non-streaming JSON response.
 *
 * Providers report tool calls in the response content, never in billing `usage`.
 *  - OpenAI family: choices[].message.tool_calls[].function.name
 *  - Anthropic: content[] blocks of type tool_use / server_tool_use
 *  - Google: candidates[].content.parts[].functionCall.name
 *
 * Fail-open: unknown shapes or bad JSON yield nothing.
 */
final class ToolCalls
{
    public const MAX_TOOL_NAMES = 20;

    /**
     * Tool names in a decoded response body.
     *
     * @return list<string>
     */
    public static function toolNamesFromBody(mixed $body): array
    {
        $names = [];
        if (! is_array($body)) {
            return $names;
        }
        foreach (self::items($body['choices'] ?? null) as $choice) {
            $calls = is_array($choice) && is_array($choice['message'] ?? null) ? ($choice['message']['tool_calls'] ?? null) : null;
            foreach (self::items($calls) as $call) {
                $fn = is_array($call) ? ($call['function'] ?? null) : null;
                if (is_array($fn) && is_string($fn['name'] ?? null)) {
                    $names[] = $fn['name'];
                }
            }
        }
        foreach (self::items($body['content'] ?? null) as $block) {
            if (is_array($block) && in_array($block['type'] ?? null, ['tool_use', 'server_tool_use'], true)
                && is_string($block['name'] ?? null)) {
                $names[] = $block['name'];
            }
        }
        foreach (self::items($body['candidates'] ?? null) as $cand) {
            $parts = is_array($cand) && is_array($cand['content'] ?? null) ? ($cand['content']['parts'] ?? null) : null;
            foreach (self::items($parts) as $part) {
                $fc = is_array($part) ? ($part['functionCall'] ?? null) : null;
                if (is_array($fc) && is_string($fc['name'] ?? null)) {
                    $names[] = $fc['name'];
                }
            }
        }

        return $names;
    }

    /**
     * $tags plus `tool_call_count` / `tool_names` when the response made tool calls.
     *
     * @param array<string, mixed> $tags
     * @return array<string, mixed>
     */
    public static function addToolCallTags(array $tags, ?string $responseBody): array
    {
        if ($responseBody === null || $responseBody === ''
            || (! str_contains($responseBody, 'tool_') && ! str_contains($responseBody, 'functionCall'))) {
            return $tags;
        }
        try {
            $names = self::toolNamesFromBody(json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return $tags;
        }
        if ($names === []) {
            return $tags;
        }
        $tags['tool_call_count'] = count($names);
        $tags['tool_names'] = implode(',', array_slice($names, 0, self::MAX_TOOL_NAMES));

        return $tags;
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
