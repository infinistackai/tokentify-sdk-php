<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Per-call attribution for SDK events: tag() / session() / agentRun() / track*() options.
 *
 * The SDK does not read `X-Tokentify-*` request headers: those are the Tokentify gateway's interface only.
 * In SDK mode the fields come from tag() (`user_id`, `account_id`, `session_id`, `trace_id`, `app_name`,
 * `environment`, `bucket` / `bucket_name` and any custom dimension), session(), agentRun(), track*() options,
 * `.env` / setup() defaults, and the OpenAI `user` request-body field (`user_id`, as the gateway does).
 *
 * Any `X-Tokentify-*` header found on a call to an AI provider is removed before it leaves the process and
 * ignored, so ids never reach the provider. Calls to other hosts (e.g. the gateway) are not touched.
 *
 * Identity fields are capped at 128 chars (the gateway's limit). `feature` and `customer_id` are not tracked
 * (removed in 0.5.0). Everything here is fail-open: it must never throw into the caller's request.
 */
final class Attribution
{
    public const HEADER_PREFIX = 'x-tokentify-';

    public const MAX_VALUE_LEN = 256;

    /** Identity fields are capped like the gateway; custom dimensions keep 256. */
    public const MAX_ID_LEN = 128;

    /** @var list<string> */
    public const ID_FIELDS = ['user_id', 'account_id', 'session_id', 'trace_id', 'app_name', 'environment'];

    public const MAX_KEY_LEN = 32;

    public const MAX_META_KEYS = 32;

    /** Fields no longer tracked on any path (SDK or gateway). Dropped from every event. @var list<string> */
    public const REMOVED_FIELDS = ['feature', 'customer_id'];

    /** Names a custom dimension may not use: collector-owned or first-class fields. Same list as the gateway. */
    public const RESERVED_META = [
        'user_id', 'account_id', 'model', 'provider', 'bucket', 'feature', 'customer_id', 'trace_id', 'session_id',
        'source', 'bucket_source', 'bucket_rule_uid', 'requested_bucket_slug', 'block_reason', 'event_type',
        'usage_source', 'pricing_status', 'cost_status', 'gateway_cost_usd', 'tags', 'tags_dropped',
        'bucket_fallback', 'status', 'duration_ms', 'parent_span_id', 'span_id', 'tool_name', 'tool_input',
        'tool_output', 'via', 'request_id', 'api_key', 'token',
        'app_name', 'environment', 'endpoint', 'input_tokens', 'output_tokens', 'total_tokens', 'cache_read_tokens',
        'cache_write_tokens', 'tool_call_count', 'tool_names', 'speech_kind', 'billable_unit', 'billable_quantity',
        'metering_kind', 'canonical_unit', 'canonical_quantity', 'audio_seconds', 'input_characters', 'metering_gap',
    ];

    /** Attribution keys accepted directly as track*() options. @var list<string> */
    public const CALL_ATTRIBUTION_KEYS = ['session_id', 'user_id', 'account_id', 'trace_id'];

    /**
     * Copy per-call attribution options (e.g. `session_id`) onto $tags. Per-call values win over
     * tag() / session() context. Also drops REMOVED_FIELDS however they got into $tags.
     *
     * @param array<string, mixed> $tags
     * @param array<string, mixed> $options
     */
    public static function applyCallAttribution(array &$tags, array $options): void
    {
        self::dropRemovedFields($tags);
        self::ensureRequestId($tags, $options);
        foreach (self::CALL_ATTRIBUTION_KEYS as $key) {
            $value = $options[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $cleaned = self::cleanValue($value);
            if ($cleaned !== '') {
                $tags[$key] = $cleaned;
            }
        }
        self::capIdFields($tags);
    }

    /**
     * Cut identity fields to MAX_ID_LEN chars in place, as the gateway does.
     *
     * @param array<string, mixed> $tags
     */
    public static function capIdFields(array &$tags): void
    {
        foreach (self::ID_FIELDS as $key) {
            if (! array_key_exists($key, $tags) || $tags[$key] === null) {
                continue;
            }
            $cleaned = mb_substr(self::cleanValue($tags[$key]), 0, self::MAX_ID_LEN);
            if ($cleaned !== '') {
                $tags[$key] = $cleaned;
            } else {
                unset($tags[$key]);
            }
        }
    }

    /**
     * The OpenAI-style `user` field of a JSON request body, or "". The gateway uses it as `user_id`;
     * the SDK does the same. Never throws.
     */
    public static function bodyEndUser(?string $body): string
    {
        if ($body === null || $body === '' || ! str_contains($body, '"user"')) {
            return '';
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }
        if (! is_array($data) || ! isset($data['user']) || ! is_string($data['user'])) {
            return '';
        }

        return mb_substr(self::cleanValue($data['user']), 0, self::MAX_ID_LEN);
    }

    /** Request id in the gateway's format (`req_` + 24 hex chars). */
    public static function newRequestId(): string
    {
        return 'req_' . substr(bin2hex(random_bytes(16)), 0, 24);
    }

    /**
     * Set `$tags['request_id']`: the caller's `request_id` option, else one already in tags, else a new id.
     *
     * @param array<string, mixed> $tags
     * @param array<string, mixed>|null $options
     */
    public static function ensureRequestId(array &$tags, ?array $options = null): void
    {
        $given = self::cleanValue($options['request_id'] ?? '');
        if ($given === '') {
            $given = self::cleanValue($tags['request_id'] ?? '');
        }
        $tags['request_id'] = $given !== '' ? $given : self::newRequestId();
    }

    /**
     * Remove fields that are no longer tracked (`feature`, `customer_id`) in place.
     *
     * @param array<string, mixed> $tags
     */
    public static function dropRemovedFields(array &$tags): void
    {
        foreach (self::REMOVED_FIELDS as $key) {
            unset($tags[$key]);
        }
    }

    /** `Plan-Tier` -> `plan_tier`. Empty string when unusable. */
    public static function normalizeMetaKey(string $raw): string
    {
        $key = str_replace('-', '_', strtolower(trim($raw)));

        return strlen($key) <= self::MAX_KEY_LEN && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) === 1 ? $key : '';
    }

    /**
     * Header names to remove from a request: every `X-Tokentify-*` header. Values are ignored, not recorded.
     *
     * @param iterable<string, mixed> $headers Header name => values
     * @return list<string>
     */
    public static function tokentifyHeaderNames(iterable $headers): array
    {
        $names = [];
        foreach ($headers as $name => $_) {
            if (is_string($name) && str_starts_with(strtolower($name), self::HEADER_PREFIX)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public static function cleanValue(mixed $value): string
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, self::MAX_VALUE_LEN);
    }
}
