<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Immutable token counts for a single LLM API call.
 */
final class TokenUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {
    }

    public function cacheTokens(): int
    {
        return $this->cacheReadTokens + $this->cacheWriteTokens;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheTokens();
    }

    /**
     * @return array<string, int>
     */
    public function toTrackOptions(bool $includeZeros = false): array
    {
        $opts = [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
        if ($includeZeros || $this->cacheReadTokens > 0) {
            $opts['cache_read_tokens'] = $this->cacheReadTokens;
        }
        if ($includeZeros || $this->cacheWriteTokens > 0) {
            $opts['cache_write_tokens'] = $this->cacheWriteTokens;
        }

        return $opts;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'cache_tokens' => $this->cacheTokens(),
            'total_tokens' => $this->totalTokens(),
        ];
    }

    /**
     * @param array<string, mixed> $usage
     */
    public static function fromInternalUsage(array $usage): self
    {
        $inputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $usage['cache_read_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? $usage['cache_write_tokens'] ?? 0);

        return new self($inputTokens, $outputTokens, $cacheRead, $cacheWrite);
    }
}
