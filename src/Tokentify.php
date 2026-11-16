<?php

declare(strict_types=1);

namespace Tokentify;

use Psr\Http\Client\ClientInterface;
use Tokentify\Exception\ConfigurationException;
use Tokentify\Exception\ValidationException;
use Tokentify\Http\MeteredHttpClient;
use Tokentify\Http\MeteringMiddleware;
use Tokentify\Http\Transport;

/**
 * Tokentify PHP SDK (`composer require tokentify/tokentify-sdk`): out-of-band AI usage metering.
 *
 *     use Tokentify\Tokentify;
 *
 *     $stack->push(Tokentify::guzzleMiddleware());          // reads TOKENTIFY_API_KEY from .env and meters AI calls
 *
 *     Tokentify::withTags(['user_id' => 'u_123', 'account_id' => 'acme'], fn () => $client->post(...));
 *
 * Token usage and tool-call names are read from the provider's response: no proxy or gateway.
 * Attribution comes from tag() / session() / agentRun(); `X-Tokentify-*` headers are for the Tokentify
 * gateway only and are ignored here (removed from provider calls so they never reach the provider).
 *
 * Metering starts on its own the first time it is needed (the PHP equivalent of the Python SDK's
 * import-time start): it never throws and never touches the network. If the API key is missing, metering
 * stays off and a warning is logged once (Tokentify::enabled() is then false). To configure it yourself
 * (bucket, verification), call Tokentify::setup(...) first, or set TOKENTIFY_AUTO_SETUP=false.
 */
final class Tokentify
{
    public const VERSION = LlmEvents::SDK_VERSION;

    public const DEFAULT_COLLECTOR_URL = 'http://api.tokentify.ai';

    private static ?Emitter $emitter = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    /** @var array<string, mixed> Process-wide tags (PHP-FPM: one request per process). */
    private static array $tags = [];

    private static bool $initialized = false;

    private static ?string $apiKey = null;

    /** null = automatic start not tried yet in this process. */
    private static ?bool $autoEnabled = null;

    private static ?Transport $transport = null;

    /** @var (callable(string, string, array<string, string>, ?string, float): array{0: ?int, 1: array<string, string>, 2: string})|null */
    private static $httpHandler = null;

    // ── Setup ────────────────────────────────────────────────────────

    /**
     * Load global secrets and defaults from a `.env` file into the environment (step 1, before setup()).
     *
     * The API key (`TOKENTIFY_API_KEY`) must come from `.env` or the process environment; it cannot be
     * passed to setup(). Existing environment values are never overridden.
     *
     * @param string|null $path Explicit `.env` path. Otherwise `TOKENTIFY_DOTENV_PATH`, or the first `.env`
     *                          found searching upward from the current working directory.
     * @return bool True when a `.env` file was found and loaded.
     */
    public static function loadEnv(?string $path = null): bool
    {
        return Env::load($path) !== null;
    }

    /**
     * Recommended setup (step 2): verify the collector, resolve the bucket, start metering.
     *
     *     Tokentify::loadEnv();
     *     Tokentify::setup();            // bucket from TOKENTIFY_BUCKET in .env
     *
     * Verification runs by default; set `TOKENTIFY_VERIFY=false` in `.env` for local dev.
     * Any other init() option can be passed by name (e.g. `requireBucket: false`, `timeout: 10`).
     *
     * @param array<string, string>|string|null $providerBuckets
     */
    public static function setup(
        ?string $bucket = null,
        ?string $appName = null,
        ?string $environment = null,
        ?bool $loadEnvFile = null,
        ?string $collectorUrl = null,
        bool $debug = false,
        ?bool $verify = null,
        array|string|null $providerBuckets = null,
        mixed ...$options,
    ): void {
        self::rejectApiKey($options);
        $verify ??= Env::bool(Env::VERIFY, true);
        $providerBuckets ??= Env::first(Env::PROVIDER_BUCKETS);

        self::init(...array_merge([
            'bucket' => $bucket,
            'appName' => $appName,
            'environment' => $environment,
            'loadEnvFile' => $loadEnvFile ?? Env::bool(Env::LOAD_DOTENV, false),
            'collectorUrl' => $collectorUrl,
            'debug' => $debug,
            'verifyConnection' => $verify,
            'verifyApiKey' => $verify,
            'providerBuckets' => $providerBuckets,
        ], $options));
    }

    /**
     * Idempotent loadEnv() + setup() for long-running workers, tests and hot reload.
     */
    public static function ensureSetup(?string $bucket = null, ?string $envPath = null, mixed ...$options): void
    {
        self::loadEnv($envPath);
        if (! self::$initialized || $bucket !== null) {
            self::setup(...array_merge($options, ['bucket' => $bucket, 'loadEnvFile' => false]));
        }
    }

    /**
     * Advanced initialization. Prefer loadEnv() + setup() for new integrations.
     *
     * The API key is global: set `TOKENTIFY_API_KEY` in `.env`. It cannot be passed here.
     * Other settings: argument, then environment (after `.env` load), then default.
     *
     * @param string|null $bucket Dashboard bucket name. Falls back to `TOKENTIFY_BUCKET`.
     * @param string|null $bucketName Deprecated alias for $bucket.
     * @param string|null $appName Logical app label on events. Falls back to `TOKENTIFY_APP_NAME`.
     * @param string|null $environment Deployment label in event metadata (default `production`).
     * @param bool $loadEnvFile Load `.env` first (legacy: prefer loadEnv()).
     * @param string|null $collectorUrl Collector base URL. Falls back to `TOKENTIFY_COLLECTOR_URL`, then the default.
     * @param bool $debug Log SDK activity with error_log(). Also `TOKENTIFY_DEBUG=true`.
     * @param float $timeout HTTP timeout (seconds) for collector requests.
     * @param bool $verifyTls Verify TLS certificates for HTTPS collectors.
     * @param bool $verifyConnection Ping `GET /health` now; throws if unreachable.
     * @param bool $verifyApiKey POST an empty batch to `/v1/ingest` to validate the API key.
     * @param array<string, string>|string|null $providerBuckets Provider => bucket map, or `openai:openai,anthropic:anthropic`.
     * @param bool $requireBucket When false no bucket is needed; the collector routes events.
     * @param list<string> $trackingFields Legacy group_key_N / group_value_N metadata (UsageMeter\Tokentify).
     * @param bool $strictTrackingValidation Legacy: throw when a tracking field is missing from an event.
     * @param bool $failOnDeliveryError Throw from flush() when the collector rejects or cannot be reached.
     * @param int $batchSize Events per POST; the queue is sent when it reaches this size.
     * @param bool $autoFlushOnBatch Send automatically when the queue reaches $batchSize.
     *
     * @throws ConfigurationException
     * @throws Exception\TransportException When verification fails
     */
    public static function init(
        ?string $bucket = null,
        ?string $bucketName = null,
        ?string $appName = null,
        ?string $environment = null,
        bool $loadEnvFile = false,
        ?string $collectorUrl = null,
        bool $debug = false,
        float $timeout = 30.0,
        bool $verifyTls = true,
        bool $verifyConnection = false,
        bool $verifyApiKey = true,
        array|string|null $providerBuckets = null,
        bool $requireBucket = true,
        array $trackingFields = [],
        bool $strictTrackingValidation = false,
        bool $failOnDeliveryError = false,
        int $batchSize = Emitter::BATCH_SIZE,
        bool $autoFlushOnBatch = true,
        mixed ...$extra,
    ): void {
        self::rejectApiKey($extra);
        foreach (array_keys($extra) as $name) {
            throw ConfigurationException::unknownOption((string) $name);
        }

        if ($loadEnvFile) {
            Env::load();
        }
        $debug = $debug || Env::bool(Env::DEBUG, false);

        $key = Env::first(Env::API_KEY);
        if ($key === null) {
            throw ConfigurationException::missingApiKey();
        }

        $resolvedBucket = trim((string) ($bucket ?: ($bucketName ?: (Env::first(Env::BUCKET) ?? ''))));
        $resolvedProviderBuckets = self::parseProviderBuckets($providerBuckets);
        if ($requireBucket && $resolvedBucket === '' && $resolvedProviderBuckets === []) {
            throw ConfigurationException::missingBucket();
        }
        if ($resolvedProviderBuckets === []) {
            $resolvedProviderBuckets = self::parseProviderBuckets(Env::first(Env::PROVIDER_BUCKETS));
        }

        $nextConfig = [
            'bucket_name' => $resolvedBucket,
            'app_name' => trim((string) $appName) !== '' ? trim((string) $appName) : Env::first(Env::APP_NAME),
            'environment' => trim((string) $environment) !== '' ? trim((string) $environment) : (Env::first(Env::ENVIRONMENT) ?? 'production'),
            'collector_url' => trim((string) $collectorUrl) !== '' ? trim((string) $collectorUrl) : (Env::first(Env::COLLECTOR) ?? self::DEFAULT_COLLECTOR_URL),
            'debug' => $debug,
            'timeout' => $timeout,
            'verify_tls' => $verifyTls,
            'provider_buckets' => $resolvedProviderBuckets,
            'tracking_fields' => array_values(array_filter(array_map(static fn ($f): string => trim((string) $f), $trackingFields), static fn (string $f): bool => $f !== '')),
            'strict_tracking_validation' => $strictTrackingValidation,
            'fail_on_delivery_error' => $failOnDeliveryError,
            'batch_size' => max(1, $batchSize),
            'auto_flush_on_batch' => $autoFlushOnBatch,
        ];

        $sameConfig = self::$initialized && self::$emitter !== null && self::$apiKey === $key && self::$config === $nextConfig;
        if (! $sameConfig) {
            if (self::$emitter !== null) {
                try {
                    self::$emitter->shutdown(2.0);
                } catch (\Throwable) {
                }
            }
            self::$config = $nextConfig;
            self::$apiKey = $key;
            self::$emitter = new Emitter(
                $nextConfig['collector_url'],
                $key,
                $debug,
                $timeout,
                $verifyTls,
                $failOnDeliveryError,
                $nextConfig['batch_size'],
                $autoFlushOnBatch,
                self::$httpHandler,
            );
            self::$initialized = true;
        }

        if ($verifyConnection) {
            self::$emitter->checkConnection();
        }
        if ($verifyApiKey) {
            self::$emitter->checkIngestAuth();
        }
    }

    /** True after a successful init() / setup() (or automatic start) in this process. */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Start metering from `.env` with no bucket required (the collector routes events), if not started yet.
     * Never throws and never touches the network unless `TOKENTIFY_VERIFY=true`. This is what the Python SDK
     * does on `import tokentify`; PHP runs it the first time metering is needed.
     *
     * @return bool Whether metering is on.
     */
    public static function enabled(): bool
    {
        if (self::$initialized || self::$autoEnabled !== null) {
            return self::$initialized;
        }
        self::$autoEnabled = false;
        try {
            self::loadEnv();
            if (! Env::bool(Env::AUTO_SETUP, true)) {
                return self::$initialized;
            }
            self::setup(verify: Env::bool(Env::VERIFY, false), requireBucket: false);
            self::$autoEnabled = self::$initialized;
        } catch (\Throwable $e) {
            error_log('tokentify: metering disabled, your AI calls are unaffected: ' . $e->getMessage());
        }

        return self::$initialized;
    }

    // ── Attribution ──────────────────────────────────────────────────

    /**
     * Set tags attached to all subsequent events. Named arguments or one array:
     *
     *     Tokentify::tag(user_id: 'u123', team: 'backend');
     *     Tokentify::tag(['user_id' => 'u123']);
     *
     * Tags stay until changed. The returned scope's restore() puts the previous values back
     * (or use withTags()). `bucket` routes the call, like the gateway's bucket header.
     */
    public static function tag(mixed ...$tags): TagScope
    {
        $tags = self::namedArgs($tags, 'tag()');
        if (array_key_exists('bucket', $tags)) {
            $tags['bucket_name'] = $tags['bucket'];
            unset($tags['bucket']);
        }
        $previous = [];
        foreach ($tags as $key => $value) {
            $previous[$key] = array_key_exists($key, self::$tags) ? self::$tags[$key] : TagScope::MISSING;
            self::$tags[$key] = $value;
        }

        return new TagScope($previous, static function (array $previous): void {
            foreach ($previous as $key => $old) {
                if ($old === TagScope::MISSING) {
                    unset(self::$tags[$key]);
                } else {
                    self::$tags[$key] = $old;
                }
            }
        });
    }

    /**
     * Run $fn with $tags applied, then restore the previous values (Python's `with tokentify.tag(...)`).
     *
     * @template T
     * @param array<string, mixed> $tags
     * @param callable(): T $fn
     * @return T
     */
    public static function withTags(array $tags, callable $fn): mixed
    {
        $scope = self::tag($tags);
        try {
            return $fn();
        } finally {
            $scope->restore();
        }
    }

    /**
     * Current tags merged with the global config tags (`app_name`, `environment`, default bucket).
     *
     * @return array<string, mixed>
     */
    public static function currentTags(): array
    {
        $result = [];
        if (! empty(self::$config['app_name'])) {
            $result['app_name'] = self::$config['app_name'];
        }
        if (! empty(self::$config['environment'])) {
            $result['environment'] = self::$config['environment'];
        }
        foreach (self::$tags as $key => $value) {
            $result[$key] = $value;
        }
        // Default bucket unless provider_buckets routes it per request.
        if (! array_key_exists('bucket_name', $result) && empty(self::$config['provider_buckets'])
            && ! empty(self::$config['bucket_name'])) {
            $result['bucket_name'] = self::$config['bucket_name'];
        }

        return $result;
    }

    /**
     * Attach `session_id` to events. With $fn: for its duration, returning its result. Without $fn: until
     * the returned TagScope is restored. $endOnExit also calls endSession() at the end.
     *
     *     Tokentify::session('conv_abc123', fn () => $client->post(...));
     */
    public static function session(string $sessionId, ?callable $fn = null, bool $endOnExit = false): mixed
    {
        self::requireInitialized('session()');
        $sid = trim($sessionId);
        if ($sid === '') {
            throw new \InvalidArgumentException('tokentify: session() requires a non-empty session_id');
        }
        $previous = self::$tags['session_id'] ?? null;
        self::$tags['session_id'] = $sid;

        $scope = new TagScope([], static function () use ($previous, $sid, $endOnExit): void {
            if ($previous !== null) {
                self::$tags['session_id'] = $previous;
            } else {
                unset(self::$tags['session_id']);
            }
            if ($endOnExit) {
                self::endSession($sid);
            }
        });
        if ($fn === null) {
            return $scope;
        }
        try {
            return $fn();
        } finally {
            $scope->restore();
        }
    }

    /**
     * SDK-owned agent tool metering.
     *
     *     $run = Tokentify::agentRun();
     *     $result = $run->call('get_weather', ['city' => 'Paris'], fn () => fetchWeather('Paris'));
     */
    public static function agentRun(?string $traceId = null, ?string $parentSpanId = null): AgentRun
    {
        self::requireInitialized('agentRun()');

        return new AgentRun($traceId, $parentSpanId);
    }

    /**
     * Wrap a callable so each call is metered as an agent tool (Python's `@tokentify.tool`).
     * `tool_input` is `['args' => [...positional], ...named]`. Closures need a $name.
     *
     *     $getWeather = Tokentify::tool(fn (string $city) => fetchWeather($city), 'get_weather');
     *     $getWeather('Paris');
     */
    public static function tool(callable $fn, ?string $name = null): \Closure
    {
        $toolName = $name ?? self::callableName($fn);

        return static function (mixed ...$args) use ($fn, $toolName): mixed {
            $input = [];
            $positional = array_values(array_filter($args, 'is_int', ARRAY_FILTER_USE_KEY));
            if ($positional !== []) {
                $input['args'] = $positional;
            }
            foreach ($args as $key => $value) {
                if (is_string($key)) {
                    $input[$key] = $value;
                }
            }

            return AgentRun::executeTrackedTool($toolName, $input, static fn () => $fn(...$args))['result'] ?? null;
        };
    }

    // ── Tracking ─────────────────────────────────────────────────────

    /**
     * Track a custom usage event (non-LLM metering, or manual reporting).
     *
     * @param string $eventType e.g. `embedding`, `image_gen`
     * @param string $service e.g. `openai`, `custom`
     * @param string $operation e.g. `embed`, `generate`
     * @param int|float $units Units consumed
     * @param string $unitType Unit name (default `tokens`)
     * @param float $costUsd Accepted for parity with the Python SDK; pricing is done by the collector.
     * @param array<string, mixed>|null $metadata Merged over the current tags
     */
    public static function track(
        string $eventType,
        string $service,
        string $operation,
        int|float $units = 0,
        string $unitType = 'tokens',
        float $costUsd = 0.0,
        ?array $metadata = null,
    ): void {
        self::requireInitialized('track()');
        $tags = self::currentTags();
        foreach ($metadata ?? [] as $k => $v) {
            $tags[(string) $k] = $v;
        }
        Attribution::dropRemovedFields($tags);
        Attribution::ensureRequestId($tags);

        // Quantity under the discovery key (custom metrics auto-detect it) and the canonical billable_* keys.
        if ($units) {
            $tags[$unitType] = $units;
        }
        $tags['billable_unit'] = $unitType;
        $tags['billable_quantity'] = $units;

        $bucketName = LlmEvents::pull($tags, 'bucket_name');
        $appName = LlmEvents::pull($tags, 'app_name');

        $event = [
            'event_id' => LlmEvents::uuidV4(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'provider' => (string) (($tags['provider'] ?? '') ?: ($service ?: 'custom')),
            'model' => (string) (($tags['model'] ?? '') ?: ($operation ?: ($eventType ?: 'custom'))),
            'endpoint' => $operation,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'status' => (string) (($tags['status'] ?? '') ?: 'success'),
            'sdk_version' => self::VERSION,
            'event_type' => $eventType,
            'metadata' => $tags,
        ];
        if ($bucketName !== null && $bucketName !== '') {
            $event['bucket_name'] = (string) $bucketName;
        }
        if ($appName !== null && $appName !== '') {
            $event['app_name'] = (string) $appName;
        }
        self::emit($event);
    }

    /**
     * Track an LLM call with explicit (or vendor-alias) token counts. Named arguments or one array:
     *
     *     $usage = Tokentify::trackLlm(provider: 'anthropic', model: 'claude-haiku-4-5', input_tokens: 1000,
     *                                  output_tokens: 200, cache_read_tokens: 500);
     *
     * Aliases: `prompt_tokens`, `completion_tokens`, `cache_read_input_tokens`, `cache_creation_input_tokens`.
     */
    public static function trackLlm(mixed ...$options): TokenUsage
    {
        self::requireInitialized('trackLlm()');
        $options = self::namedArgs($options, 'trackLlm()');
        $provider = self::requireProviderAndModel($options, 'trackLlm()');

        $usage = LlmEvents::resolveTokenUsage($options, provider: $provider);
        self::attachContextLimitMetadata($options);
        self::emit(LlmEvents::buildLlmIngestEvent($options, $usage, static fn (): array => self::tagsForProvider($provider), self::VERSION));

        return $usage;
    }

    /**
     * Parse a provider JSON response and track usage in one call. Explicit counts in $options win.
     *
     * $responseBody may be the raw JSON string, a decoded array, a PSR-7 response (its status fills
     * `http_status` / `status` when not given), or an object with toArray() (e.g. openai-php responses).
     *
     * @param array<string, mixed> $options
     */
    public static function trackFromResponse(array $options, mixed $responseBody): TokenUsage
    {
        self::requireInitialized('trackFromResponse()');
        $provider = self::requireProviderAndModel($options, 'trackFromResponse()');
        $body = self::bodyText($responseBody, $options);

        $usage = LlmEvents::resolveTokenUsage($options, $body, $provider);
        self::attachContextLimitMetadata($options, $body);
        self::emit(LlmEvents::buildLlmIngestEvent($options, $usage, static fn (): array => self::tagsForProvider($provider), self::VERSION));

        return $usage;
    }

    /**
     * Track a speech (STT / TTS) event with explicit billable units. Named arguments or one array:
     *
     *     Tokentify::trackSpeech(provider: 'openai', model: 'whisper-1', kind: 'stt',
     *                            billable_unit: 'seconds', billable_quantity: 12.5, endpoint: 'audio/transcriptions');
     */
    public static function trackSpeech(mixed ...$options): SpeechUsage
    {
        self::requireInitialized('trackSpeech()');
        $options = self::namedArgs($options, 'trackSpeech()');
        $provider = self::requireProviderAndModel($options, 'trackSpeech()');

        $usage = SpeechEvents::resolveSpeechUsage($options, provider: $provider);
        self::emit(SpeechEvents::buildSpeechIngestEvent($options, $usage, static fn (): array => self::tagsForProvider($provider), self::VERSION));

        return $usage;
    }

    /**
     * Parse a provider speech response and track usage. For TTS pass the request body (character count).
     *
     * @param array<string, mixed> $options
     */
    public static function trackFromSpeechResponse(array $options, mixed $responseBody, mixed $requestBody = null): SpeechUsage
    {
        self::requireInitialized('trackFromSpeechResponse()');
        $provider = self::requireProviderAndModel($options, 'trackFromSpeechResponse()');
        $body = self::bodyText($responseBody, $options);
        $request = $requestBody === null ? null : self::bodyText($requestBody);

        $usage = SpeechEvents::resolveSpeechUsage($options, $body, $request, $provider);
        $tagsFn = static fn (): array => self::tagsForProvider($provider);
        if ($usage->billableUnit === 'tokens' && ($usage->inputTokens + $usage->outputTokens) > 0) {
            $event = LlmEvents::buildLlmIngestEvent($options, new TokenUsage($usage->inputTokens, $usage->outputTokens), $tagsFn, self::VERSION);
        } else {
            $event = SpeechEvents::buildSpeechIngestEvent($options, $usage, $tagsFn, self::VERSION);
        }
        self::emit($event);

        return $usage;
    }

    /**
     * Track one agent tool execution (provider-agnostic, `event_type: "tool"`). Named arguments or one array.
     *
     * Required: `trace_id`, `tool_name`, `status` (`success` | `error`). Optional: `span_id` (generated when
     * omitted), `parent_span_id`, `tool_input`, `tool_output`, `duration_ms`, `account_id`, `user_id`, `timestamp`.
     */
    public static function trackTool(mixed ...$options): void
    {
        self::requireInitialized('trackTool()');
        $options = self::namedArgs($options, 'trackTool()');
        $provider = (string) (($options['provider'] ?? '') ?: ToolEvents::DEFAULT_TOOL_PROVIDER);
        self::emit(ToolEvents::buildToolIngestEvent($options, static fn (): array => self::tagsForProvider($provider), self::VERSION));
    }

    /**
     * Send every usage event recorded so far, blocking up to $timeout seconds. Events are also sent when the
     * queue fills and when PHP shuts down; call this at the end of each job in long-running workers.
     */
    public static function flush(float $timeout = 5.0): void
    {
        self::requireInitialized('flush()');
        self::$emitter?->flush($timeout);
    }

    /**
     * End a metered session and remove its context-window state on the collector.
     *
     * @return bool True when the collector reported the session was cleared.
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

    // ── Automatic capture ────────────────────────────────────────────

    /**
     * Guzzle middleware that meters every call to a known AI provider (OpenAI, Anthropic, Gemini, Bedrock, ...),
     * streaming included. Calls to other hosts pass through untouched.
     *
     *     $stack = HandlerStack::create();
     *     $stack->push(Tokentify::guzzleMiddleware(), 'tokentify');
     *     $client = new GuzzleHttp\Client(['handler' => $stack]);
     */
    public static function guzzleMiddleware(): MeteringMiddleware
    {
        return new MeteringMiddleware(self::transport());
    }

    /**
     * Wrap any PSR-18 client (Symfony HttpClient's Psr18Client, Guzzle, ...) so AI calls are metered.
     * Pass the result to SDKs that accept a PSR-18 client, e.g. `OpenAI::factory()->withHttpClient(...)`.
     */
    public static function httpClient(ClientInterface $client): MeteredHttpClient
    {
        return new MeteredHttpClient($client, self::transport());
    }

    /** @internal Shared transport used by the middleware and the PSR-18 decorator. */
    public static function transport(): Transport
    {
        return self::$transport ??= new Transport(
            static fn (array $event) => self::emit($event),
            static fn (): array => self::currentTags(),
            static fn (string $provider, array $tags): array => self::resolveBucketForProvider($provider, $tags),
            static fn (): bool => self::enabled(),
        );
    }

    // ── Advanced ─────────────────────────────────────────────────────

    /** The active emitter (null before setup). */
    public static function emitter(): ?Emitter
    {
        return self::$emitter;
    }

    /**
     * Resolved configuration (no API key).
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return self::$config;
    }

    /**
     * Route collector requests through your own HTTP code (tests, custom egress). Applies to emitters
     * created after this call: `fn(method, url, headers, body, timeout): [status|null, headers, body]`.
     *
     * @param (callable(string, string, array<string, string>, ?string, float): array{0: ?int, 1: array<string, string>, 2: string})|null $handler
     */
    public static function setHttpHandler(?callable $handler): void
    {
        self::$httpHandler = $handler;
    }

    /**
     * Forget all state (config, tags, queued events, automatic-start attempt). For test suites.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$emitter = null;
        self::$config = [];
        self::$tags = [];
        self::$initialized = false;
        self::$apiKey = null;
        self::$autoEnabled = null;
        self::$transport = null;
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * Apply per-provider bucket routing when no explicit bucket tag is set.
     *
     * @param array<string, mixed> $tags
     * @return array<string, mixed>
     */
    public static function resolveBucketForProvider(string $provider, array $tags): array
    {
        if (! empty($tags['bucket_name'])) {
            return $tags;
        }
        $providerKey = strtolower(trim($provider));
        $providerBuckets = self::$config['provider_buckets'] ?? [];
        if ($providerKey !== '' && isset($providerBuckets[$providerKey])) {
            $tags['bucket_name'] = $providerBuckets[$providerKey];
        } elseif (! empty(self::$config['bucket_name'])) {
            $tags['bucket_name'] = self::$config['bucket_name'];
        }

        return $tags;
    }

    /**
     * Provider => bucket map from an array or `openai:openai,anthropic:anthropic`.
     *
     * @param array<string, string>|string|null $value
     * @return array<string, string>
     */
    public static function parseProviderBuckets(array|string|null $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $pairs = [];
        if (is_array($value)) {
            foreach ($value as $provider => $bucket) {
                $pairs[] = [(string) $provider, (string) $bucket];
            }
        } else {
            foreach (explode(',', $value) as $piece) {
                if (str_contains($piece, ':')) {
                    $pairs[] = explode(':', trim($piece), 2);
                }
            }
        }
        $result = [];
        foreach ($pairs as [$provider, $bucket]) {
            $provider = strtolower(trim($provider));
            $bucket = trim($bucket);
            if ($provider !== '' && $bucket !== '') {
                $result[$provider] = $bucket;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function tagsForProvider(string $provider = ''): array
    {
        return self::resolveBucketForProvider($provider, self::currentTags());
    }

    /**
     * Enqueue an event (applying legacy tracking_fields grouping when configured).
     *
     * @param array<string, mixed> $event
     * @throws ValidationException When strict tracking validation is on and a field is missing
     */
    private static function emit(array $event): void
    {
        if (self::$emitter === null) {
            return;
        }
        $fields = self::$config['tracking_fields'] ?? [];
        if ($fields !== [] && is_array($event['metadata'] ?? null)) {
            if (! empty(self::$config['strict_tracking_validation'])) {
                $missing = TrackingMetadata::missingKeys($event['metadata'], $fields);
                if ($missing !== []) {
                    throw ValidationException::missingTrackingMetadata($missing, [
                        'provider' => $event['provider'] ?? null,
                        'model' => $event['model'] ?? null,
                    ]);
                }
            }
            $event['metadata'] = TrackingMetadata::applyFlatGroups($event['metadata'], $fields);
        }
        self::$emitter->enqueue($event);
        if (! empty(self::$config['debug'])) {
            error_log('tokentify: enqueued event ' . json_encode($event));
        }
    }

    private static function requireInitialized(string $action): void
    {
        if (! self::$initialized) {
            self::enabled();
        }
        if (! self::$initialized) {
            throw ConfigurationException::notInitialized($action);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function requireProviderAndModel(array $options, string $action): string
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if ($provider === '') {
            throw new \InvalidArgumentException("tokentify: {$action} requires provider");
        }
        if (empty($options['model'])) {
            throw new \InvalidArgumentException("tokentify: {$action} requires model");
        }

        return $provider;
    }

    /**
     * Fill the configured context-limit metadata key from the response when available.
     *
     * @param array<string, mixed> $options
     */
    private static function attachContextLimitMetadata(array &$options, ?string $responseBody = null): void
    {
        $key = Env::first(Env::CONTEXT_LIMIT_KEY) ?? 'max_tokens';
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        $existing = $metadata[$key] ?? null;
        if (! (is_int($existing) && $existing > 0) && $responseBody !== null) {
            $limit = ContextLimit::extractFromResponse($responseBody, $key);
            if ($limit !== null) {
                $metadata[$key] = $limit;
            }
        }
        $options['metadata'] = $metadata;
    }

    /**
     * Response / request body as text. PSR-7 responses also fill `http_status` / `status` in $options.
     *
     * @param array<string, mixed> $options
     */
    private static function bodyText(mixed $body, array &$options = []): string
    {
        if ($body instanceof \Psr\Http\Message\ResponseInterface) {
            $code = $body->getStatusCode();
            $options['http_status'] ??= $code;
            $options['status'] ??= $code >= 200 && $code < 300 ? 'success' : 'error';
            $stream = $body->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $text = (string) $stream;
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            return $text;
        }
        if ($body instanceof \Psr\Http\Message\MessageInterface) {
            return (string) $body->getBody();
        }
        if (is_string($body)) {
            return $body;
        }
        if (is_object($body) && method_exists($body, 'toArray')) {
            $body = $body->toArray();
        }
        if (is_array($body) || $body instanceof \JsonSerializable) {
            return json_encode($body) ?: '';
        }

        return (string) $body;
    }

    /**
     * Named arguments, or one array as the only positional argument.
     *
     * @param array<int|string, mixed> $args
     * @return array<string, mixed>
     */
    private static function namedArgs(array $args, string $action): array
    {
        if (count($args) === 1 && array_key_exists(0, $args) && is_array($args[0])) {
            $args = $args[0];
        }
        foreach (array_keys($args) as $key) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException("tokentify: {$action} takes named arguments or one array of options");
            }
        }

        /** @var array<string, mixed> $args */
        return $args;
    }

    /**
     * @param array<int|string, mixed> $options
     */
    private static function rejectApiKey(array $options): void
    {
        foreach (['api_key', 'apiKey'] as $name) {
            if (array_key_exists($name, $options)) {
                throw ConfigurationException::apiKeyNotAllowed();
            }
        }
    }

    private static function callableName(callable $fn): string
    {
        if (is_string($fn)) {
            $parts = explode('::', $fn);

            return end($parts);
        }
        if (is_array($fn)) {
            return (string) $fn[1];
        }
        $name = (new \ReflectionFunction(\Closure::fromCallable($fn)))->getName();
        if (str_contains($name, '{closure')) {
            throw new \InvalidArgumentException(
                "tokentify: tool() needs a name for closures, e.g. Tokentify::tool(fn (\$city) => ..., 'get_weather')"
            );
        }
        $parts = explode('\\', $name);

        return end($parts);
    }
}
