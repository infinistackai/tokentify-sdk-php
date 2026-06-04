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
    private const VERSION = '0.1.1';

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
     *   collector_url?: string,
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

        $collectorUrl = self::normalizeString($options['collector_url'] ?? '')
            ?: self::normalizeString((string) (getenv('USAGEMETER_COLLECTOR_URL') ?: getenv('COLLECTOR_URL') ?: ''))
            ?: 'http://205.209.126.182:8006';

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
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     * @throws ValidationException
     */
    private static function enqueueIngestEvent(array $options): void
    {
        if (self::$emitter === null) {
            throw ConfigurationException::meterNotInitialized();
        }

        $tags = self::buildMetadata($options['metadata'] ?? null);
        $tf = self::$config['tracking_fields'] ?? [];
        if (is_array($tf) && $tf !== []) {
            if (! empty(self::$config['strict_tracking_validation'])) {
                $missing = TrackingMetadata::missingKeys($tags, $tf);
                if ($missing !== []) {
                    throw ValidationException::missingTrackingMetadata($missing, [
                        'provider' => $options['provider'] ?? null,
                        'model' => $options['model'] ?? null,
                    ]);
                }
            }
            $tags = TrackingMetadata::applyFlatGroups($tags, $tf);
        }

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
        $event['sdk_version'] = self::VERSION;

        if ($tags !== []) {
            $event['metadata'] = $tags;
        }

        self::$emitter->enqueue($event);

        if (! empty(self::$config['debug'])) {
            error_log('[usagemeter-php] Enqueued event: ' . json_encode($event, JSON_THROW_ON_ERROR));
        }
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
