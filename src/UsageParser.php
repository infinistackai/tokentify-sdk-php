<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Extract token usage (cache included) from provider JSON responses, using one table of provider formats.
 *
 * Covers every model of a provider (models share their provider's format). A new provider with its own
 * format is one entry in {@see self::formats()}; OpenAI-compatible providers need none (unknown providers
 * are read by their response shape).
 */
final class UsageParser
{
    /** Providers whose prompt/input count includes cached tokens. Same set as the collector and gateway. */
    public const OPENAI_FAMILY = ['openai', 'mistral', 'groq', 'perplexity', 'fireworks', 'openrouter', 'azure'];

    private const ANTHROPIC_ONLY_KEYS = ['cache_read_input_tokens', 'cache_creation_input_tokens'];

    /** @var array<string, UsageFormat>|null */
    private static ?array $formats = null;

    public static function normalizeProvider(string $provider): string
    {
        return strtolower(trim($provider));
    }

    public static function isOpenaiFamily(string $provider): bool
    {
        return in_array(self::normalizeProvider($provider), self::OPENAI_FAMILY, true);
    }

    /** Subtract cached prompt tokens already included in OpenAI-family input counts. */
    public static function normalizeOpenaiFamilyUsage(TokenUsage $usage, string $provider): TokenUsage
    {
        if (! self::isOpenaiFamily($provider)) {
            return $usage;
        }
        if ($usage->cacheReadTokens <= 0 || $usage->inputTokens < $usage->cacheReadTokens) {
            return $usage;
        }

        return new TokenUsage(
            $usage->inputTokens - $usage->cacheReadTokens,
            $usage->outputTokens,
            $usage->cacheReadTokens,
            $usage->cacheWriteTokens,
        );
    }

    /** OpenAI and OpenAI-compatible APIs: Chat Completions, Responses API, DeepSeek-style cache hits. */
    public static function openaiFormat(): UsageFormat
    {
        return self::formats()['openai'];
    }

    /**
     * The one table of provider usage formats.
     *
     * @return array<string, UsageFormat>
     */
    public static function formats(): array
    {
        if (self::$formats !== null) {
            return self::$formats;
        }
        $openai = new UsageFormat(
            [['usage'], ['response', 'usage']],
            ['prompt_tokens', 'input_tokens'],
            ['completion_tokens', 'output_tokens'],
            ['prompt_tokens_details.cached_tokens', 'input_tokens_details.cached_tokens', 'prompt_cache_hit_tokens'],
            [],
            true,
        );
        $formats = [];
        foreach (self::OPENAI_FAMILY as $name) {
            $formats[$name] = $openai;
        }
        // Anthropic Messages (also the message_start / message_delta stream events).
        $formats['anthropic'] = new UsageFormat(
            [['usage'], ['message', 'usage']],
            ['input_tokens'],
            ['output_tokens'],
            ['cache_read_input_tokens'],
            ['cache_creation_input_tokens'],
        );
        // Google Gemini: promptTokenCount includes cachedContentTokenCount.
        $formats['google'] = new UsageFormat(
            [['usageMetadata']],
            ['promptTokenCount'],
            ['candidatesTokenCount'],
            ['cachedContentTokenCount'],
            [],
            true,
        );
        // AWS Bedrock Converse (camelCase) and Anthropic-on-Bedrock InvokeModel (snake_case); input excludes cache.
        $formats['bedrock'] = new UsageFormat(
            [['usage']],
            ['inputTokens', 'input_tokens'],
            ['outputTokens', 'output_tokens'],
            ['cacheReadInputTokens', 'cache_read_input_tokens'],
            ['cacheWriteInputTokens', 'cache_creation_input_tokens'],
        );
        // Cohere v1 (meta.billed_units) and v2 (usage.billed_units). No prompt caching.
        $formats['cohere'] = new UsageFormat(
            [['meta', 'billed_units'], ['usage', 'billed_units']],
            ['input_tokens'],
            ['output_tokens'],
        );

        return self::$formats = $formats;
    }

    /** Parse a non-streaming JSON response body for token usage. */
    public static function fromProviderResponse(string $responseBody, string $provider): TokenUsage
    {
        try {
            $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new TokenUsage();
        }

        return is_array($data) ? self::fromUsageArray($data, $provider) : new TokenUsage();
    }

    /**
     * Normalize vendor-native usage blocks to TokenUsage.
     *
     * @param array<string, mixed> $data
     */
    public static function fromUsageArray(array $data, string $provider): TokenUsage
    {
        $provider = self::normalizeProvider($provider);
        try {
            $format = self::formats()[$provider] ?? self::guessFormat($data);
            $usage = $format->parse($data);
            if ($usage === null && $provider === 'bedrock') {
                $usage = self::bedrockLegacy($data);
            }

            return $usage ?? new TokenUsage();
        } catch (\Throwable) {
            return new TokenUsage();
        }
    }

    /**
     * Extract usage from a single parsed SSE JSON event (streaming).
     *
     * @param array<string, mixed> $event
     */
    public static function fromSseEvent(array $event, string $provider): TokenUsage
    {
        return self::fromUsageArray($event, $provider);
    }

    /**
     * Format for a provider not in the table, from the response's own shape.
     *
     * @param array<string, mixed> $data
     */
    private static function guessFormat(array $data): UsageFormat
    {
        if (isset($data['usageMetadata']) && is_array($data['usageMetadata'])) {
            return self::formats()['google'];
        }
        $usage = UsageFormat::dig($data, ['usage']) ?? UsageFormat::dig($data, ['message', 'usage']);
        if (is_array($usage)) {
            foreach (self::ANTHROPIC_ONLY_KEYS as $key) {
                if (array_key_exists($key, $usage)) {
                    return self::formats()['anthropic'];
                }
            }
        }

        return self::openaiFormat();
    }

    /**
     * Older Bedrock InvokeModel bodies with top-level counts (Llama, Titan). No caching there.
     *
     * @param array<string, mixed> $data
     */
    private static function bedrockLegacy(array $data): ?TokenUsage
    {
        if (array_key_exists('prompt_token_count', $data)) {
            return new TokenUsage(
                (int) ($data['prompt_token_count'] ?? 0),
                (int) ($data['generation_token_count'] ?? 0),
            );
        }
        if (array_key_exists('inputTextTokenCount', $data)) {
            $results = $data['results'] ?? [[]];
            $result = is_array($results) && isset($results[0]) && is_array($results[0]) ? $results[0] : [];

            return new TokenUsage(
                (int) ($data['inputTextTokenCount'] ?? 0),
                (int) ($result['tokenCount'] ?? 0),
            );
        }

        return null;
    }
}
