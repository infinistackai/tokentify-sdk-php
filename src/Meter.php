<?php

declare(strict_types=1);

namespace UsageMeter;

use UsageMeter\Exception\ConfigurationException;
use UsageMeter\Exception\TransportException;
use UsageMeter\Exception\ValidationException;

/**
 * UsageMeter / Tokentify PHP SDK — Bearer API key, POST /v1/ingest, JSON metadata on usage_events.
 *
 * Tokentify-style flat grouping: pass `tracking_fields` in init(), then set the same keys
 * in `metadata` (or via `tag()`). The SDK adds `group_key_1` / `group_value_1`, … for analytics.
 *
 * Example (SMS):
 *
 *   Meter::init(['bucket' => 'my-app']);
 *   Meter::trackSms(['segments' => 2, 'provider' => 'twilio', 'model' => 'transactional']);
 *   Meter::flush();
 *
 * Example (Tokentify):
 *
 *   Tokentify::init('my-app', ['account_id', 'user_id']); // or legacy array with explicit tracking_fields
 *   Meter::tag(['account_id' => 'hotel_abc', 'user_id' => 'rahul']);
 *   Meter::track([...]);
 *   // or: Meter::trackWithVendorMetadata([...], $sameMetadataYouSentToOpenAI);
 */
final class Meter
{
    private const VERSION = LlmEvents::SDK_VERSION;

    /** Fixed collector base URL for production ingest (not overridden by .env). */
    public const DEFAULT_COLLECTOR_URL = 'http://tokentify.com:4006';

    /** @var list<string> */
    private const API_KEY_ENVS = [
        'USAGEMETER_API_KEY',
        'UM_API_KEY',
        'USAGEMETER_TOKEN',
        'UM_TOKEN',
    ];

    private static ?Emitter $emitter = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    private static bool $initialized = false;

    /** @var array<string, string> */
    private static array $tags = [];

    /**
     * @param array{
     *   bucket?: string,
     *   api_key?: string,
     *   app_name?: string,
     *   environment?: string,
     *   load_env_file?: bool,
     *   debug?: bool,
     *   timeout?: float,
     *   verify_tls?: bool,
     *   verify_connection?: bool,
     *   verify_api_key?: bool,
     *   tracking_fields?: list<string>,
     *   strict_tracking_validation?: bool,
     *   fail_on_delivery_error?: bool,
     *   batch_size?: int,
     *   auto_flush_on_batch?: bool,
     * } $options
     *
     * @throws ConfigurationException
     * @throws TransportException When verify_connection or verify_api_key fails
     */
    public static function init(array $options = []): void
    {
        $loadEnv = $options['load_env_file'] ?? true;
        if ($loadEnv && class_exists(\Dotenv\Dotenv::class)) {
            try {
                $dotenv = \Dotenv\Dotenv::createImmutable(self::cwdCandidates());
                $dotenv->safeLoad();
            } catch (\Throwable) {
                // optional dependency
            }
        }

        $apiKey = self::normalizeString($options['api_key'] ?? '') ?: self::apiKeyFromEnv();
        if ($apiKey === '') {
            throw ConfigurationException::missingApiKey();
        }

        $bucket = self::normalizeString($options['bucket'] ?? '')
            ?: self::normalizeString((string) (getenv('USAGEMETER_BUCKET') ?: ''));

        if ($bucket === '') {
            throw ConfigurationException::missingBucket();
        }

        $collectorUrl = self::DEFAULT_COLLECTOR_URL;

        $environment = self::normalizeString($options['environment'] ?? '')
            ?: self::normalizeString((string) (getenv('USAGEMETER_ENVIRONMENT') ?: ''))
            ?: 'production';

        $appName = isset($options['app_name']) ? self::normalizeString((string) $options['app_name']) : null;
        if ($appName === '') {
            $appName = null;
        }

        $debug = (bool) ($options['debug'] ?? false);
        $timeout = (float) ($options['timeout'] ?? 30.0);
        $verifyTls = (bool) ($options['verify_tls'] ?? true);
        $verifyConnection = (bool) ($options['verify_connection'] ?? false);
        $verifyApiKey = (bool) ($options['verify_api_key'] ?? true);
        $failOnDeliveryError = (bool) ($options['fail_on_delivery_error'] ?? false);
        $batchSize = max(1, (int) ($options['batch_size'] ?? 50));
        $autoFlushOnBatch = (bool) ($options['auto_flush_on_batch'] ?? true);
        $strictTracking = (bool) ($options['strict_tracking_validation'] ?? false);
        $trackingFields = self::normalizeTrackingFields($options['tracking_fields'] ?? null);

        $nextConfig = [
            'bucket_name' => $bucket,
            'app_name' => $appName,
            'environment' => $environment,
            'collector_url' => $collectorUrl,
            'debug' => $debug,
            'timeout' => $timeout,
            'verify_tls' => $verifyTls,
            'tracking_fields' => $trackingFields,
            'strict_tracking_validation' => $strictTracking,
            'fail_on_delivery_error' => $failOnDeliveryError,
            'batch_size' => $batchSize,
            'auto_flush_on_batch' => $autoFlushOnBatch,
        ];

        $prev = self::$config;
        unset($prev['_api_key']);
        $same = self::$initialized
            && self::$emitter !== null
            && $prev === $nextConfig
            && (self::$config['_api_key'] ?? '') === $apiKey;

        if (! $same) {
            if (self::$emitter !== null) {
                try {
                    self::$emitter->flush();
                } catch (\Throwable) {
                }
            }
            self::$emitter = new Emitter(
                $collectorUrl,
                $apiKey,
                $debug,
                $timeout,
                $verifyTls,
                $failOnDeliveryError,
                $batchSize,
                $autoFlushOnBatch,
            );
            $nextConfig['_api_key'] = $apiKey;
            self::$config = $nextConfig;
            self::$initialized = true;
        }

        if (self::$emitter === null) {
            return;
        }

        if ($verifyConnection) {
            self::$emitter->checkConnection();
        }
        if ($verifyApiKey) {
            self::$emitter->checkIngestAuth();
        }
    }

    /**
     * Merge tags into metadata for subsequent track / trackSms events (request-scoped).
     *
     * @param array<string, string|int|float|bool> $tags
     */
    public static function tag(array $tags): void
    {
        foreach ($tags as $k => $v) {
            self::$tags[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @throws TransportException When fail_on_delivery_error was enabled and ingest fails
     */
    public static function flush(): void
    {
        if (self::$emitter !== null) {
            self::$emitter->flush();
        }
    }

    /**
     * Record outbound SMS using the same usage_events row shape: input_tokens = segment count.
     *
     * @param array{
     *   segments?: int,
     *   provider?: string,
     *   model?: string,
     *   endpoint?: string,
     *   status?: string,
     *   metadata?: array<string, mixed>,
     *   event_id?: string,
     * } $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    public static function trackSms(array $options = []): void
    {
        $segments = (int) ($options['segments'] ?? 1);
        if ($segments < 1) {
            $segments = 1;
        }
        $payload = $options;
        unset($payload['segments']);
        $payload['provider'] = self::normalizeString((string) ($payload['provider'] ?? '')) ?: 'sms';
        $payload['model'] = self::normalizeString((string) ($payload['model'] ?? '')) ?: 'outbound';
        $payload['input_tokens'] = $segments;
        $payload['output_tokens'] = 0;
        if (! isset($payload['endpoint']) || ! is_string($payload['endpoint']) || $payload['endpoint'] === '') {
            $payload['endpoint'] = '/sms';
        }
        self::enqueueIngestEvent($payload);
    }

    /**
     * Generic ingest event (custom metering). Maps to collector UsageEvent (snake_case).
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    public static function track(array $options): void
    {
        $provider = self::normalizeString((string) ($options['provider'] ?? ''));
        $model = self::normalizeString((string) ($options['model'] ?? ''));
        if ($provider === '' || $model === '') {
            throw ValidationException::missingProviderOrModel();
        }
        self::enqueueIngestEvent($options);
    }

    /**
     * Record usage like {@see track()}, merging $vendorMetadata into `metadata` before ingest.
     *
     * Use the same associative array you pass to a vendor “request metadata” parameter
     * (e.g. OpenAI `chat.completions.create(..., metadata: [...])`) so `account_id` / `user_id`
     * stay aligned with the architecture doc. Keys in `$options['metadata']` overwrite
     * `$vendorMetadata` on collision.
     *
     * @param array<string, mixed> $options Same shape as {@see track()}
     * @param array<string, mixed> $vendorMetadata Request-scoped dict (often `account_id`, `user_id`)
     */
    public static function trackWithVendorMetadata(array $options, array $vendorMetadata): void
    {
        $fromTrack = isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [];
        $options['metadata'] = array_merge($vendorMetadata, $fromTrack);

        self::track($options);
    }

    /**
     * Track a custom usage event (Python SDK {@code track()} shape).
     *
     * @param array<string, mixed>|null $metadata
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    public static function trackCustom(
        string $eventType,
        string $service,
        string $operation,
        int|float $units = 0,
        string $unitType = 'tokens',
        float $costUsd = 0.0,
        ?array $metadata = null,
    ): void {
        self::requireInitialized('trackCustom()');
        $tags = self::getTagsForEvents();
        if ($metadata !== null) {
            foreach ($metadata as $k => $v) {
                $tags[(string) $k] = $v;
            }
        }

        if ($units) {
            $tags[$unitType] = $units;
        }
        $tags['billable_unit'] = $unitType;
        $tags['billable_quantity'] = $units;

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

        $provider = (string) ($tags['provider'] ?? $service ?: 'custom');
        $model = (string) ($tags['model'] ?? $operation ?: $eventType ?: 'custom');

        $event = [
            'event_id' => self::uuidV4(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'provider' => $provider,
            'model' => $model,
            'endpoint' => $operation,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'status' => (string) ($tags['status'] ?? 'success'),
            'sdk_version' => self::VERSION,
            'event_type' => $eventType,
            'metadata' => $tags,
        ];
        if (is_string($bucketName) && $bucketName !== '') {
            $event['bucket_name'] = $bucketName;
        } elseif (($cfgBucket = self::$config['bucket_name'] ?? '') !== '' && is_string($cfgBucket)) {
            $event['bucket_name'] = $cfgBucket;
        }
        if (is_string($appName) && $appName !== '') {
            $event['app_name'] = $appName;
        }

        self::finalizeAndEmitEvent($event);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    public static function trackLlm(array $options): TokenUsage
    {
        self::requireInitialized('trackLlm()');
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if ($provider === '') {
            throw new \InvalidArgumentException('tokentifyai-usagemeter: track_llm() requires provider=');
        }
        if (empty($options['model'])) {
            throw new \InvalidArgumentException('tokentifyai-usagemeter: track_llm() requires model=');
        }

        $usage = LlmEvents::resolveTokenUsage($options, provider: $provider);
        $event = LlmEvents::buildLlmIngestEvent(
            $options,
            $usage,
            static fn (): array => self::getTagsForEvents(),
            self::VERSION,
        );
        self::finalizeAndEmitEvent($event);

        return $usage;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    public static function trackFromResponse(array $options, string $responseBody): TokenUsage
    {
        self::requireInitialized('trackFromResponse()');
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if ($provider === '') {
            throw new \InvalidArgumentException(
                'tokentifyai-usagemeter: track_from_response() requires provider='
            );
        }
        if (empty($options['model'])) {
            throw new \InvalidArgumentException(
                'tokentifyai-usagemeter: track_from_response() requires model='
            );
        }

        $usage = LlmEvents::resolveTokenUsage($options, $responseBody, $provider);
        $event = LlmEvents::buildLlmIngestEvent(
            $options,
            $usage,
            static fn (): array => self::getTagsForEvents(),
            self::VERSION,
        );
        self::finalizeAndEmitEvent($event);

        return $usage;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    public static function trackSpeech(array $options): SpeechUsage
    {
        self::requireInitialized('trackSpeech()');
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if ($provider === '') {
            throw new \InvalidArgumentException('tokentifyai-usagemeter: track_speech() requires provider=');
        }
        if (empty($options['model'])) {
            throw new \InvalidArgumentException('tokentifyai-usagemeter: track_speech() requires model=');
        }

        $usage = SpeechEvents::resolveSpeechUsage($options, provider: $provider);
        $event = SpeechEvents::buildSpeechIngestEvent(
            $options,
            $usage,
            static fn (): array => self::getTagsForEvents(),
            self::VERSION,
        );
        self::finalizeAndEmitEvent($event);

        return $usage;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    public static function trackFromSpeechResponse(
        array $options,
        string $responseBody,
        ?string $requestBody = null,
    ): SpeechUsage {
        self::requireInitialized('trackFromSpeechResponse()');
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if ($provider === '') {
            throw new \InvalidArgumentException(
                'tokentifyai-usagemeter: track_from_speech_response() requires provider='
            );
        }
        if (empty($options['model'])) {
            throw new \InvalidArgumentException(
                'tokentifyai-usagemeter: track_from_speech_response() requires model='
            );
        }

        $usage = SpeechEvents::resolveSpeechUsage(
            $options,
            $responseBody,
            $requestBody,
            $provider,
        );
        if ($usage->billableUnit === 'tokens' && ($usage->inputTokens + $usage->outputTokens) > 0) {
            $tokenUsage = new TokenUsage($usage->inputTokens, $usage->outputTokens);
            $event = LlmEvents::buildLlmIngestEvent(
                $options,
                $tokenUsage,
                static fn (): array => self::getTagsForEvents(),
                self::VERSION,
            );
        } else {
            $event = SpeechEvents::buildSpeechIngestEvent(
                $options,
                $usage,
                static fn (): array => self::getTagsForEvents(),
                self::VERSION,
            );
        }
        self::finalizeAndEmitEvent($event);

        return $usage;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     * @throws \InvalidArgumentException
     */
    public static function trackTool(array $options): void
    {
        self::requireInitialized('trackTool()');
        $event = ToolEvents::buildToolIngestEvent(
            $options,
            static fn (): array => self::getTagsForEvents(),
            self::VERSION,
        );
        self::finalizeAndEmitEvent($event);
    }

    /**
     * End a metered session and remove its context-window state from Redis.
     */
    public static function endSession(string $sessionId, float $timeout = 5.0): bool
    {
        self::requireInitialized('endSession()');
        $sid = trim($sessionId);
        if ($sid === '' || self::$emitter === null) {
            return false;
        }

        return self::$emitter->endSession($sid, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    private static function enqueueIngestEvent(array $options): void
    {
        self::requireInitialized('track()');

        $tags = self::buildMetadata($options['metadata'] ?? null);

        $eventId = isset($options['event_id']) && is_string($options['event_id']) && $options['event_id'] !== ''
            ? $options['event_id']
            : self::uuidV4();

        $event = [
            'event_id' => $eventId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'provider' => (string) $options['provider'],
            'model' => (string) $options['model'],
            'input_tokens' => (int) ($options['input_tokens'] ?? 0),
            'output_tokens' => (int) ($options['output_tokens'] ?? 0),
            'status' => (string) ($options['status'] ?? 'success'),
            'sdk_version' => self::VERSION,
            'metadata' => $tags,
        ];

        if (isset($options['endpoint']) && is_string($options['endpoint']) && $options['endpoint'] !== '') {
            $event['endpoint'] = $options['endpoint'];
        }
        if (isset($options['latency_ms'])) {
            $event['latency_ms'] = (int) $options['latency_ms'];
        }
        if (isset($options['http_status'])) {
            $event['http_status'] = (int) $options['http_status'];
        }
        if (isset($options['error_code']) && is_string($options['error_code'])) {
            $event['error_code'] = $options['error_code'];
        }
        if (isset($options['cache_read_tokens'])) {
            $event['cache_read_tokens'] = (int) $options['cache_read_tokens'];
        }
        if (isset($options['cache_write_tokens'])) {
            $event['cache_write_tokens'] = (int) $options['cache_write_tokens'];
        }

        $bn = self::$config['bucket_name'] ?? '';
        if (is_string($bn) && $bn !== '') {
            $event['bucket_name'] = $bn;
        }
        $app = self::$config['app_name'] ?? null;
        if (is_string($app) && $app !== '') {
            $event['app_name'] = $app;
        }

        self::finalizeAndEmitEvent($event);
    }

    /**
     * @param array<string, mixed> $event
     *
     * @throws ValidationException
     */
    private static function finalizeAndEmitEvent(array $event): void
    {
        if (isset($event['metadata']) && is_array($event['metadata'])) {
            $tags = $event['metadata'];
            $tf = self::$config['tracking_fields'] ?? [];
            if (is_array($tf) && $tf !== []) {
                if (! empty(self::$config['strict_tracking_validation'])) {
                    $missing = TrackingMetadata::missingKeys($tags, $tf);
                    if ($missing !== []) {
                        throw ValidationException::missingTrackingMetadata($missing, [
                            'provider' => $event['provider'] ?? null,
                            'model' => $event['model'] ?? null,
                        ]);
                    }
                }
                $tags = TrackingMetadata::applyFlatGroups($tags, $tf);
            }
            $event['metadata'] = $tags;
        }

        self::emitEvent($event);
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function emitEvent(array $event): void
    {
        if (self::$emitter === null) {
            throw ConfigurationException::meterNotInitialized();
        }

        self::$emitter->enqueue($event);

        if (! empty(self::$config['debug'])) {
            error_log('[usagemeter-php] Enqueued event: ' . json_encode($event, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @throws ConfigurationException
     */
    private static function requireInitialized(string $method): void
    {
        if (! self::$initialized || self::$emitter === null) {
            throw ConfigurationException::meterNotInitialized();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function getTagsForEvents(): array
    {
        $tags = self::buildMetadata(null);
        $bn = self::$config['bucket_name'] ?? '';
        if (is_string($bn) && $bn !== '') {
            $tags['bucket_name'] = $bn;
        }
        $app = self::$config['app_name'] ?? null;
        if (is_string($app) && $app !== '') {
            $tags['app_name'] = $app;
        }

        return $tags;
    }

    private static function apiKeyFromEnv(): string
    {
        foreach (self::API_KEY_ENVS as $var) {
            $v = getenv($var);
            if (is_string($v) && self::normalizeString($v) !== '') {
                return self::normalizeString($v);
            }
        }

        return '';
    }

    private static function normalizeString(string $s): string
    {
        return trim($s);
    }

    /**
     * @return list<string>
     */
    private static function normalizeTrackingFields(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $f) {
            $s = trim((string) $f);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private static function cwdCandidates(): array
    {
        $candidates = [getcwd() ?: '.'];
        if (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
            $candidates[] = dirname($_SERVER['DOCUMENT_ROOT']);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param array<string, mixed>|null $extra
     * @return array<string, mixed>
     */
    private static function buildMetadata(?array $extra): array
    {
        $meta = [
            'environment' => (string) (self::$config['environment'] ?? 'production'),
        ];
        foreach (self::$tags as $k => $v) {
            $meta[$k] = $v;
        }
        if ($extra !== null) {
            foreach ($extra as $k => $v) {
                $meta[(string) $k] = $v;
            }
        }

        return $meta;
    }

    private static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
