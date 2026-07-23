<?php

declare(strict_types=1);

namespace UsageMeter;

/**
 * Build tool-usage ingest events for agent tool executions.
 */
final class ToolEvents
{
    public const TOOL_EVENT_TYPE = 'tool';

    public const DEFAULT_TOOL_PROVIDER = 'agent';

    public const DEFAULT_TOOL_MODEL = 'tool';

    public const DEFAULT_TOOL_ENDPOINT = 'tool';

    /** @var list<string> */
    public const TOOL_METADATA_KEYS = [
        'event_type',
        'trace_id',
        'span_id',
        'parent_span_id',
        'tool_name',
        'tool_input',
        'tool_output',
        'duration_ms',
        'account_id',
        'user_id',
    ];

    /** @var list<string> */
    private const VALID_TOOL_STATUSES = ['success', 'error'];

    /**
     * @param array<string, mixed> $options
     * @param callable(): array<string, mixed> $getTagsFn
     * @return array<string, mixed>
     */
    public static function buildToolIngestEvent(
        array $options,
        callable $getTagsFn,
        string $sdkVersion = LlmEvents::SDK_VERSION,
    ): array {
        $traceId = self::requireNonEmptyString($options['trace_id'] ?? null, 'trace_id');
        $spanId = self::requireNonEmptyString($options['span_id'] ?? null, 'span_id');
        $toolName = self::requireNonEmptyString($options['tool_name'] ?? null, 'tool_name');
        $status = self::normalizeStatus($options['status'] ?? null);
        $durationMs = self::normalizeDurationMs($options['duration_ms'] ?? null);

        $tags = $getTagsFn();
        $extraMetadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        foreach ($extraMetadata as $k => $v) {
            $tags[(string) $k] = $v;
        }

        $tags['event_type'] = self::TOOL_EVENT_TYPE;
        $tags['trace_id'] = $traceId;
        $tags['span_id'] = $spanId;
        $tags['tool_name'] = $toolName;
        $tags['status'] = $status;

        $parentSpanId = $options['parent_span_id'] ?? null;
        if ($parentSpanId === null || (is_string($parentSpanId) && trim($parentSpanId) === '')) {
            $tags['parent_span_id'] = null;
        } else {
            $tags['parent_span_id'] = trim((string) $parentSpanId);
        }

        if (array_key_exists('tool_input', $options)) {
            $tags['tool_input'] = $options['tool_input'];
        }
        if (array_key_exists('tool_output', $options)) {
            $tags['tool_output'] = $options['tool_output'];
        }
        if ($durationMs !== null) {
            $tags['duration_ms'] = $durationMs;
        }

        $accountId = $options['account_id'] ?? null;
        if ($accountId !== null && trim((string) $accountId) !== '') {
            $tags['account_id'] = trim((string) $accountId);
        }
        $userId = $options['user_id'] ?? null;
        if ($userId !== null && trim((string) $userId) !== '') {
            $tags['user_id'] = trim((string) $userId);
        }

        $bucketName = null;
        if (array_key_exists('bucket_name', $tags)) {
            $bucketName = $tags['bucket_name'];
            unset($tags['bucket_name']);
        }
        $appName = null;
        if (array_key_exists('app_name', $tags)) {
            $appName = $tags['app_name'];
            unset($tags['app_name']);
        }

        $provider = trim((string) ($options['provider'] ?? self::DEFAULT_TOOL_PROVIDER));
        if ($provider === '') {
            $provider = self::DEFAULT_TOOL_PROVIDER;
        }
        $model = trim((string) ($options['model'] ?? self::DEFAULT_TOOL_MODEL));
        if ($model === '') {
            $model = self::DEFAULT_TOOL_MODEL;
        }
        $endpoint = $options['endpoint'] ?? self::DEFAULT_TOOL_ENDPOINT;

        $latencyMs = $options['latency_ms'] ?? null;
        if ($latencyMs === null) {
            $latencyMs = $durationMs;
        }

        $eventId = isset($options['event_id']) && is_string($options['event_id']) && $options['event_id'] !== ''
            ? $options['event_id']
            : self::uuidV4();

        $event = [
            'event_id' => $eventId,
            'event_type' => self::TOOL_EVENT_TYPE,
            'timestamp' => self::formatTimestamp($options['timestamp'] ?? null),
            'provider' => $provider,
            'model' => $model,
            'endpoint' => $endpoint,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'status' => $status,
            'sdk_version' => $sdkVersion,
            'metadata' => $tags,
        ];
        if ($latencyMs !== null) {
            $event['latency_ms'] = (int) $latencyMs;
        }
        if (is_string($bucketName) && $bucketName !== '') {
            $event['bucket_name'] = $bucketName;
        }
        if (is_string($appName) && $appName !== '') {
            $event['app_name'] = $appName;
        }
        if (array_key_exists('http_status', $options) && $options['http_status'] !== null) {
            $event['http_status'] = (int) $options['http_status'];
        }
        if (array_key_exists('error_code', $options) && $options['error_code'] !== null) {
            $event['error_code'] = $options['error_code'];
        }

        return $event;
    }

    private static function requireNonEmptyString(mixed $value, string $field): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new \InvalidArgumentException("tokentifyai-usagemeter: track_tool() requires {$field}=");
        }
        $text = trim((string) $value);
        if ($text === '') {
            throw new \InvalidArgumentException("tokentifyai-usagemeter: track_tool() requires {$field}=");
        }

        return $text;
    }

    private static function normalizeStatus(mixed $status): string
    {
        if ($status === null || (is_string($status) && trim($status) === '')) {
            throw new \InvalidArgumentException(
                "tokentifyai-usagemeter: track_tool() requires status= ('success' or 'error')"
            );
        }
        $text = strtolower(trim((string) $status));
        if (! in_array($text, self::VALID_TOOL_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "tokentifyai-usagemeter: track_tool() status must be 'success' or 'error'"
            );
        }

        return $text;
    }

    private static function normalizeDurationMs(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new \InvalidArgumentException(
                'tokentifyai-usagemeter: track_tool() duration_ms must be an int >= 0'
            );
        }
        $duration = (int) $value;
        if ($duration < 0) {
            throw new \InvalidArgumentException(
                'tokentifyai-usagemeter: track_tool() duration_ms must be an int >= 0'
            );
        }

        return $duration;
    }

    private static function formatTimestamp(mixed $timestamp): string
    {
        if (is_int($timestamp) || is_float($timestamp)) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
        }
        if (is_string($timestamp) && trim($timestamp) !== '') {
            return trim($timestamp);
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
