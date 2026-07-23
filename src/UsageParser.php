<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Extract token usage from provider JSON responses.
 */
final class UsageParser
{
    /** @var list<string> */
    private const OPENAI_FAMILY = [
        'openai',
        'mistral',
        'groq',
        'perplexity',
        'fireworks',
        'openrouter',
        'azure',
    ];

    public static function normalizeProvider(string $provider): string
    {
        return strtolower(trim($provider));
    }

    public static function normalizeOpenaiFamilyUsage(TokenUsage $usage, string $provider): TokenUsage
    {
        if (! in_array(self::normalizeProvider($provider), self::OPENAI_FAMILY, true)) {
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

    public static function fromProviderResponse(string $responseBody, string $provider): TokenUsage
    {
        try {
            /** @var mixed $data */
            $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new TokenUsage();
        }

        if (! is_array($data)) {
            return new TokenUsage();
        }

        return self::fromUsageArray($data, $provider);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromUsageArray(array $data, string $provider): TokenUsage
    {
        $provider = self::normalizeProvider($provider);
        /** @var array<string, mixed> $usage */
        $usage = [];

        if ($provider === 'anthropic') {
            $u = $data['usage'] ?? null;
            if (is_array($u)) {
                $usage['input_tokens'] = $u['input_tokens'] ?? 0;
                $usage['output_tokens'] = $u['output_tokens'] ?? 0;
                $usage['cache_creation_input_tokens'] = $u['cache_creation_input_tokens'] ?? 0;
                $usage['cache_read_input_tokens'] = $u['cache_read_input_tokens'] ?? 0;
            }
        } elseif (in_array($provider, self::OPENAI_FAMILY, true)) {
            $u = $data['usage'] ?? null;
            if (is_array($u)) {
                $usage = self::parseOpenaiUsage($u);
            }
        } elseif ($provider === 'google') {
            $u = $data['usageMetadata'] ?? null;
            if (is_array($u)) {
                $usage['input_tokens'] = $u['promptTokenCount'] ?? 0;
                $usage['output_tokens'] = $u['candidatesTokenCount'] ?? 0;
            }
        } elseif ($provider === 'cohere') {
            $meta = $data['meta'] ?? null;
            if (is_array($meta)) {
                $billed = $meta['billed_units'] ?? null;
                if (is_array($billed)) {
                    $usage['input_tokens'] = $billed['input_tokens'] ?? 0;
                    $usage['output_tokens'] = $billed['output_tokens'] ?? 0;
                }
            }
        } elseif ($provider === 'bedrock') {
            if (isset($data['usage']) && is_array($data['usage'])) {
                $u = $data['usage'];
                $usage['input_tokens'] = $u['inputTokens'] ?? $u['input_tokens'] ?? 0;
                $usage['output_tokens'] = $u['outputTokens'] ?? $u['output_tokens'] ?? 0;
            } elseif (array_key_exists('prompt_token_count', $data)) {
                $usage['input_tokens'] = $data['prompt_token_count'] ?? 0;
                $usage['output_tokens'] = $data['generation_token_count'] ?? 0;
            } elseif (array_key_exists('inputTextTokenCount', $data)) {
                $results = $data['results'] ?? [[]];
                $result = is_array($results[0] ?? null) ? $results[0] : [];
                $usage['input_tokens'] = $data['inputTextTokenCount'] ?? 0;
                $usage['output_tokens'] = $result['tokenCount'] ?? 0;
            }
        }

        if ($usage === []) {
            return new TokenUsage();
        }

        return TokenUsage::fromInternalUsage($usage);
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function fromSseEvent(array $event, string $provider): TokenUsage
    {
        $provider = self::normalizeProvider($provider);
        /** @var array<string, mixed> $usage */
        $usage = [];

        if ($provider === 'anthropic') {
            $eventType = (string) ($event['type'] ?? '');
            if ($eventType === 'message_start') {
                $msg = $event['message'] ?? null;
                if (is_array($msg)) {
                    $u = $msg['usage'] ?? null;
                    if (is_array($u)) {
                        $usage['input_tokens'] = $u['input_tokens'] ?? 0;
                        $usage['cache_creation_input_tokens'] = $u['cache_creation_input_tokens'] ?? 0;
                        $usage['cache_read_input_tokens'] = $u['cache_read_input_tokens'] ?? 0;
                    }
                }
            } elseif ($eventType === 'message_delta') {
                $u = $event['usage'] ?? null;
                if (is_array($u)) {
                    $usage['output_tokens'] = $u['output_tokens'] ?? 0;
                }
            }
        } elseif (in_array($provider, self::OPENAI_FAMILY, true)) {
            $u = $event['usage'] ?? null;
            if (is_array($u)) {
                $usage = self::parseOpenaiUsage($u);
            }
        } elseif ($provider === 'google') {
            $u = $event['usageMetadata'] ?? null;
            if (is_array($u)) {
                $usage['input_tokens'] = $u['promptTokenCount'] ?? 0;
                $usage['output_tokens'] = $u['candidatesTokenCount'] ?? 0;
            }
        } elseif ($provider === 'cohere') {
            $meta = $event['meta'] ?? null;
            if (is_array($meta)) {
                $billed = $meta['billed_units'] ?? null;
                if (is_array($billed)) {
                    $usage['input_tokens'] = $billed['input_tokens'] ?? 0;
                    $usage['output_tokens'] = $billed['output_tokens'] ?? 0;
                }
            }
        }

        if ($usage === []) {
            return new TokenUsage();
        }

        return TokenUsage::fromInternalUsage($usage);
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    private static function parseOpenaiUsage(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $cached = self::openaiCachedTokens($usage);
        $out = [
            'input_tokens' => max(0, $prompt - $cached),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        ];
        if ($cached > 0) {
            $out['cache_read_input_tokens'] = $cached;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $usage
     */
    private static function openaiCachedTokens(array $usage): int
    {
        $details = $usage['prompt_tokens_details'] ?? null;
        if (! is_array($details)) {
            return 0;
        }

        return (int) ($details['cached_tokens'] ?? 0);
    }
}
