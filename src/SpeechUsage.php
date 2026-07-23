<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Immutable speech metering counts for a single API call.
 */
final class SpeechUsage
{
    public function __construct(
        public readonly string $kind,
        public readonly string $billableUnit,
        public readonly float $billableQuantity,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly bool $meteringGap = false,
    ) {
    }

    public function shouldEmit(): bool
    {
        return $this->meteringGap
            || $this->billableQuantity > 0
            || ($this->inputTokens + $this->outputTokens) > 0
            || $this->billableUnit === 'request';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'billable_unit' => $this->billableUnit,
            'billable_quantity' => $this->billableQuantity,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $quantity = $this->billableQuantity;
        if ($this->billableUnit === 'request' && $quantity <= 0) {
            $quantity = 1.0;
        }

        $meta = [
            'speech_kind' => $this->kind,
            'billable_unit' => $this->billableUnit,
            'billable_quantity' => $quantity,
        ];

        [$meteringKind, $canonicalUnit] = $this->canonicalFields();
        if ($meteringKind !== null && $canonicalUnit !== null) {
            $meta['metering_kind'] = $meteringKind;
            $meta['canonical_unit'] = $canonicalUnit;
            $meta['canonical_quantity'] = $quantity;
        }
        if ($this->kind === 'stt' && $this->billableUnit === 'seconds') {
            $meta['audio_seconds'] = $quantity;
        } elseif ($this->kind === 'tts' && $this->billableUnit === 'characters') {
            $meta['input_characters'] = (int) $quantity;
        } elseif ($this->kind === 'stt' && $this->billableUnit === 'words') {
            $wordCount = (int) $quantity;
            $meta['word_count'] = $wordCount;
            $meta['stt_words'] = $wordCount;
        } elseif ($this->billableUnit === 'request') {
            $meta['request_count'] = 1;
        }
        if ($this->meteringGap) {
            $meta['metering_gap'] = true;
        }

        return $meta;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function canonicalFields(): array
    {
        return match ($this->billableUnit) {
            'seconds' => ['stt_duration', 'seconds'],
            'characters' => ['tts_characters', 'characters'],
            'words' => ['stt_words', 'words'],
            'request' => ['api_request', 'request'],
            default => [null, null],
        };
    }

    public function defaultBucketName(): string
    {
        return $this->kind === 'stt' ? 'speech-to-text' : 'text-to-speech';
    }

    /**
     * @param array<string, mixed> $usage
     */
    public static function fromInternalUsage(array $usage): self
    {
        return new self(
            (string) ($usage['kind'] ?? 'stt'),
            (string) ($usage['billable_unit'] ?? 'seconds'),
            (float) ($usage['billable_quantity'] ?? 0),
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (bool) ($usage['metering_gap'] ?? false),
        );
    }
}
