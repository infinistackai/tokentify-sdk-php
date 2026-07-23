<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Extract speech usage from provider request/response bodies.
 */
final class SpeechParser
{
    /** @var list<string> */
    private const STT_ENDPOINTS = ['audio/transcriptions', 'audio/translations'];

    private const TTS_ENDPOINT = 'audio/speech';

    /** @var list<string> */
    private const TOKEN_USAGE_KEYS = [
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'input_tokens',
        'output_tokens',
    ];

    public static function isSpeechEndpoint(string $endpoint): bool
    {
        return in_array($endpoint, self::STT_ENDPOINTS, true) || $endpoint === self::TTS_ENDPOINT;
    }

    public static function isSttEndpoint(string $endpoint): bool
    {
        return in_array($endpoint, self::STT_ENDPOINTS, true);
    }

    public static function isTtsEndpoint(string $endpoint): bool
    {
        return $endpoint === self::TTS_ENDPOINT;
    }

    public static function parseModelFromMultipart(string $body): string
    {
        if ($body === '') {
            return 'unknown';
        }
        if (preg_match('/name="model"\r?\n\r?\n([^\r\n]+)/', $body, $matches) === 1) {
            $model = trim($matches[1]);

            return $model !== '' ? $model : 'unknown';
        }

        return 'unknown';
    }

    public static function detectModel(?string $body): string
    {
        if ($body === null || $body === '') {
            return 'unknown';
        }
        $multipartModel = self::parseModelFromMultipart($body);
        if ($multipartModel !== 'unknown') {
            return $multipartModel;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($data) && isset($data['model']) && $data['model'] !== '') {
                return (string) $data['model'];
            }
        } catch (\JsonException) {
        }

        return 'unknown';
    }

    public static function parseSttDuration(string $responseBody, string $provider): ?SpeechUsage
    {
        $provider = UsageParser::normalizeProvider($provider);
        if (! in_array($provider, ['openai', 'azure', 'unknown'], true)) {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data) || array_key_exists('error', $data)) {
            return null;
        }

        $usage = $data['usage'] ?? null;
        if (is_array($usage)) {
            if (array_key_exists('seconds', $usage) && $usage['seconds'] !== null) {
                $usageType = $usage['type'] ?? null;
                if ($usageType === null || $usageType === 'duration') {
                    $quantity = (float) $usage['seconds'];
                    if ($quantity > 0) {
                        return new SpeechUsage('stt', 'seconds', $quantity);
                    }
                }
            }
            if (self::usageHasTokens($usage)) {
                return null;
            }
        }

        $wordCount = self::parseSttWordCount($data);
        if ($wordCount !== null && $wordCount > 0) {
            return new SpeechUsage('stt', 'words', $wordCount);
        }

        return new SpeechUsage('stt', 'seconds', 0.0, meteringGap: true);
    }

    public static function parseTtsCharacters(?string $requestBody, string $provider): ?SpeechUsage
    {
        $provider = UsageParser::normalizeProvider($provider);
        if (! in_array($provider, ['openai', 'azure', 'unknown'], true)) {
            return null;
        }
        if ($requestBody === null || $requestBody === '') {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }
        $inputText = $data['input'] ?? null;
        if (! is_string($inputText) || $inputText === '') {
            return null;
        }

        return new SpeechUsage('tts', 'characters', (float) strlen($inputText));
    }

    public static function apiRequestUsage(string $kind): SpeechUsage
    {
        return new SpeechUsage($kind, 'request', 1.0);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromProviderResponse(
        array $options,
        string $responseBody,
        ?string $requestBody = null,
    ): SpeechUsage {
        $provider = UsageParser::normalizeProvider((string) ($options['provider'] ?? ''));
        $endpoint = (string) ($options['endpoint'] ?? '');

        if (self::isTtsEndpoint($endpoint)) {
            $parsed = self::parseTtsCharacters($requestBody, $provider);
            if ($parsed !== null) {
                return $parsed;
            }

            return new SpeechUsage('tts', 'characters', 0.0);
        }

        if (self::isSttEndpoint($endpoint) || str_contains($endpoint, '/audio/transcription')) {
            $parsed = self::parseSttDuration($responseBody, $provider);
            if ($parsed !== null) {
                return $parsed;
            }
            $tokenUsage = UsageParser::fromProviderResponse($responseBody, $provider);
            if ($tokenUsage->totalTokens() > 0) {
                return new SpeechUsage(
                    'stt',
                    'tokens',
                    (float) $tokenUsage->totalTokens(),
                    $tokenUsage->inputTokens,
                    $tokenUsage->outputTokens,
                );
            }

            return new SpeechUsage('stt', 'seconds', 0.0, meteringGap: true);
        }

        return new SpeechUsage(
            (string) ($options['kind'] ?? 'stt'),
            (string) ($options['billable_unit'] ?? 'seconds'),
            (float) ($options['billable_quantity'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $usage
     */
    private static function usageHasTokens(array $usage): bool
    {
        foreach (self::TOKEN_USAGE_KEYS as $key) {
            if (array_key_exists($key, $usage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseSttWordCount(array $data): ?float
    {
        $usage = $data['usage'] ?? null;
        if (! is_array($usage)) {
            return null;
        }
        foreach (['word_count', 'words'] as $key) {
            if (! array_key_exists($key, $usage) || $usage[$key] === null) {
                continue;
            }
            $quantity = (float) $usage[$key];
            if ($quantity > 0) {
                return $quantity;
            }
        }

        return null;
    }
}
