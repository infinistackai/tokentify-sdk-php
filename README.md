# Tokentify PHP SDK

Track the tokens, cost and users behind every AI call, without changing how you call your AI provider.

```bash
composer require tokentify/tokentify-sdk
```

Requires PHP 8.1+. Automatic tracking works with **Guzzle 7** and any **PSR-18** HTTP client.

## How it works

```
Your code ──────────────► OpenAI / Anthropic / Google / ...   (direct, with your own provider key)
    │
    └─ the SDK reads token usage from the response
         └─► sends it to Tokentify, with your TOKENTIFY_API_KEY
```

- Your requests go **straight to the provider**. Tokentify is not in the request path, so it is not a new point
  of failure.
- Your provider key is sent **only to the provider**, never to Tokentify.
- Prompts and model responses are **not** sent to Tokentify, only usage (tokens, model, tool names) and the fields
  you tag. Exception: functions you track with `Tokentify::tool()` or `agentRun()` send their arguments and return
  value.
- If tracking fails, your AI call still works. The SDK never throws inside your request.
- Usage is queued in memory and sent in batches of 50, and when the PHP process ends (2-second limit).

## Quick start

### 1. Add your keys to `.env`

```bash
TOKENTIFY_API_KEY=um_xxxxxxxxxxxx    # your Tokentify API key
TOKENTIFY_BUCKET=support-bot         # default bucket for usage (required by setup())
OPENAI_API_KEY=sk-...                # your provider key, used by your OpenAI client as usual
```

Keep the API key in `.env`, never in your code. `setup(api_key: ...)` is not supported. The SDK has its own
`.env` reader; frameworks that already load `.env` (Laravel, Symfony) work too.

### 2. Turn it on at startup and add the middleware

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Tokentify\Tokentify;

Tokentify::loadEnv();   // read .env
Tokentify::setup();     // check the API key, then start tracking

$stack = HandlerStack::create();
$stack->push(Tokentify::guzzleMiddleware(), 'tokentify');
$http = new Client(['handler' => $stack]);

// Use $http for your AI calls, directly or through an SDK (see "Supported clients").
$http->post('https://api.openai.com/v1/chat/completions', [
    'headers' => ['Authorization' => 'Bearer ' . getenv('OPENAI_API_KEY')],
    'json' => ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'Hello']]],
]);
```

That's all. Open **Usage** in the Tokentify dashboard and the call appears within about a minute with its
tokens and cost.

## Supported clients

PHP has no single HTTP library to hook, so you choose where tracking goes in:

| You use | Do this |
|---------|---------|
| Guzzle | Push `Tokentify::guzzleMiddleware()` on the handler stack (above). |
| [openai-php/client](https://github.com/openai-php/client) | Give it the Guzzle client with the middleware: `OpenAI::factory()->withApiKey($key)->withHttpClient($http)->make()`. Streaming works too. |
| Any PSR-18 client (Symfony `Psr18Client`, Buzz, ...) | Wrap it: `$client = Tokentify::httpClient($psr18Client);` and pass `$client` to your SDK. |
| Something else (cURL, a vendor SDK with its own transport) | Record usage yourself, see **Manual tracking**. |

```php
$openai = OpenAI::factory()
    ->withApiKey(getenv('OPENAI_API_KEY'))
    ->withHttpClient($http)            // the Guzzle client with Tokentify::guzzleMiddleware()
    ->make();

$openai->chat()->create(['model' => 'gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'Hello']]]);
```

Tracked providers: OpenAI, Azure OpenAI, Anthropic, Google (Gemini API), AWS Bedrock, Cohere, Mistral, Groq,
Perplexity, Fireworks, OpenRouter. Calls to other hosts pass through untouched.

The SDK records input, output, cache read and cache write tokens, and the names of tools the model called
(`tool_names`, `tool_call_count`).

## Say who the usage belongs to: `withTags()` / `tag()`

Run your AI calls inside `Tokentify::withTags()`. Every AI call inside gets these fields, and the previous values
come back afterwards (even if your code throws).

```php
$reply = Tokentify::withTags([
    'user_id' => 'u_123',          // the person using your app
    'account_id' => 'acme',        // their company / tenant
    'session_id' => 'chat_789',    // the conversation
    'trace_id' => 'trace_1',       // one request or workflow
    'bucket' => 'support-bot',     // bucket for these calls (overrides TOKENTIFY_BUCKET)
    'app_name' => 'support-bot',   // overrides TOKENTIFY_APP_NAME
    'environment' => 'staging',    // overrides TOKENTIFY_ENVIRONMENT
    'team' => 'search',            // custom dimension (declare it on the bucket first)
], fn () => $openai->chat()->create([...]));
```

`Tokentify::tag()` takes named arguments, applies them right away and returns a scope you restore yourself:

```php
$scope = Tokentify::tag(user_id: 'u_123', account_id: 'acme');
try {
    $openai->chat()->create([...]);
} finally {
    $scope->restore();
}
```

| Field | What it is |
|-------|-----------|
| `user_id` | The end user (person). |
| `account_id` | The end customer's company or tenant. |
| `session_id` | A conversation or session. |
| `trace_id` | One request, job or agent run. |
| `bucket` | Bucket slug or name. Same as `bucket_name`. Unknown buckets fall back to your default. |
| `app_name`, `environment` | Labels for the app and deployment (`production` by default). |
| anything else | A **custom dimension**, e.g. `team`, `plan_tier`, `region`. Declare it on the bucket in the dashboard. Use lowercase letters, digits and `_`, starting with a letter (max 32 characters). |

- If you never call `restore()`, the tags stay until you change them.
- Scopes nest. Restore the inner one first.
- Tags are read when the request is sent, so a stream that is read later keeps the tags it was sent with.
- IDs are capped at 128 characters.
- `customer_id` and `feature` are no longer tracked. Use `user_id` or `account_id` instead.

### Other ways to set the same fields

```php
// A conversation id for a block of calls
Tokentify::session('chat_789', fn () => $openai->chat()->create([...]));

// OpenAI's own `user` field is recorded as user_id (it wins over tags for that call)
$openai->chat()->create(['model' => 'gpt-4o-mini', 'messages' => [...], 'user' => 'u_123']);
```

Defaults for `app_name`, `environment` and the bucket come from `.env` or `Tokentify::setup(...)`.

## Streaming

Streaming calls are tracked the same way. Each stream is recorded once, when you finish reading it (or close it).

```php
Tokentify::withTags(['user_id' => 'u_123'], function () use ($openai) {
    $stream = $openai->chat()->createStreamed([
        'model' => 'gpt-4o-mini',
        'messages' => [...],
        'stream_options' => ['include_usage' => true],   // OpenAI only sends usage in a stream when asked
    ]);
    foreach ($stream as $chunk) {
        // ...
    }
});
```

Anthropic streams need nothing extra. With plain Guzzle, pass `'stream' => true` and read the body as usual.

## Full example

A complete, runnable app is in [`examples/support-bot`](examples/support-bot): bootstrap, tags, an agent tool,
streaming and a custom (SMS) event. The core of it:

```php
use Tokentify\Tokentify;

Tokentify::loadEnv();
Tokentify::setup();

$stack = GuzzleHttp\HandlerStack::create();
$stack->push(Tokentify::guzzleMiddleware(), 'tokentify');
$openai = OpenAI::factory()
    ->withApiKey(getenv('OPENAI_API_KEY'))
    ->withHttpClient(new GuzzleHttp\Client(['handler' => $stack]))
    ->make();

function answer($openai, string $userId, string $company, string $chatId, string $question): string
{
    return Tokentify::withTags(
        ['user_id' => $userId, 'account_id' => $company, 'session_id' => $chatId, 'team' => 'search'],
        fn () => $openai->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $question]],
        ])->choices[0]->message->content,
    );
}

echo answer($openai, 'u_123', 'acme', 'chat_789', 'What is Tokentify?');
```

In the dashboard this call shows in the **support-bot** bucket with its tokens and cost, user **u_123**,
account **acme**, session **chat_789** and team **search**.

## Agents and tools

```php
$getWeather = Tokentify::tool(fn (string $city): array => ['temp_c' => 22, 'city' => $city], 'get_weather');

$getWeather('Paris');   // each call is recorded as a tool event, with its arguments and result

// Or record a plain function, grouping tool calls under one trace
$run = Tokentify::agentRun();
$result = $run->call('get_weather', ['city' => 'Paris'], fn () => fetchWeather('Paris'));

// Tools wrapped with Tokentify::tool() share the trace while run() is active
$run->run(fn () => $getWeather('Paris'));

// Record a tool that already ran (e.g. a hosted OpenAI tool)
$run->record('web_search', ['q' => 'tokentify'], ['hits' => 3], 'success', durationMs: 120);
```

Named functions (`Tokentify::tool('strtoupper')`, `[$obj, 'method']`) are named automatically. Closures need a
name. If the tool throws, the error is recorded (status `error`) and the exception is re-thrown.

## Manual tracking

Use these when a call does not go through Guzzle or a PSR-18 client, or you already have the numbers.
Options can be named arguments or one array.

```php
// You know the token counts
Tokentify::trackLlm(
    provider: 'anthropic', model: 'claude-haiku-4-5',
    input_tokens: 1000, output_tokens: 200, cache_read_tokens: 500, cache_write_tokens: 80,
    user_id: 'u_123', session_id: 'chat_789',
);

// You have the raw provider response: a JSON string, a decoded array, a PSR-7 response,
// or an object with toArray() (e.g. an openai-php response)
Tokentify::trackFromResponse(
    ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'endpoint' => 'messages'],
    $responseBody,
);

// Anything else you want to bill (SMS, minutes, API requests, ...)
Tokentify::track('sms', 'twilio', 'send', units: 2, unitType: 'segments', metadata: ['user_id' => 'u_123']);
```

## Speech and audio

OpenAI speech endpoints are tracked automatically:

| Endpoint | Billed by |
|----------|-----------|
| `/v1/audio/transcriptions`, `/v1/audio/translations` | seconds of audio |
| `/v1/audio/speech` | characters of input text |

Use a bucket with **tracking mode: custom** and set your rates in the dashboard. For other speech providers:

```php
Tokentify::trackSpeech(
    provider: 'openai', model: 'whisper-1', kind: 'stt',
    billable_unit: 'seconds', billable_quantity: 12.5, endpoint: 'audio/transcriptions',
);

// Or from the provider's response (and request body, for text-to-speech)
Tokentify::trackFromSpeechResponse(['provider' => 'openai', 'model' => 'whisper-1', 'endpoint' => 'audio/transcriptions'], $response);
```

## Configuration

All settings are environment variables, usually in `.env`.

| Variable | Required | Default | What it does |
|----------|----------|---------|--------------|
| `TOKENTIFY_API_KEY` | Yes | | Your Tokentify API key (`um_...`). |
| `TOKENTIFY_BUCKET` | Yes* | | Default bucket. *Or pass it to `Tokentify::setup('support-bot')`, or use `TOKENTIFY_PROVIDER_BUCKETS`. `setup()` throws without one. |
| `TOKENTIFY_PROVIDER_BUCKETS` | No | | One bucket per provider, e.g. `openai:openai,anthropic:anthropic`. |
| `TOKENTIFY_APP_NAME` | No | | Default app label. |
| `TOKENTIFY_ENVIRONMENT` | No | `production` | Default environment label. |
| `TOKENTIFY_VERIFY` | No | `true` | `setup()` checks the API key and collector. Set `false` for local development. |
| `TOKENTIFY_COLLECTOR_URL` | No | hosted | For a self-hosted collector. |
| `TOKENTIFY_DEBUG` | No | `false` | Debug logging with `error_log()`. |
| `TOKENTIFY_AUTO_SETUP` | No | `true` | The first tracked call starts tracking by itself if you never called `setup()`. Set `false` to start only on `setup()`. |
| `TOKENTIFY_DOTENV_PATH` | No | | `.env` file for `loadEnv()`. By default it searches upward from the working directory. |
| `TOKENTIFY_CONTEXT_LIMIT_KEY` | No | `max_tokens` | Metadata key for the context limit read from responses. |

`setup()` also takes these as named arguments: `Tokentify::setup('support-bot', appName: 'web', environment: 'staging', verify: false)`.

### One bucket per provider

```php
Tokentify::setup(providerBuckets: ['openai' => 'openai', 'anthropic' => 'anthropic', 'google' => 'google']);
```

### Workers, queues and tests

```php
Tokentify::ensureSetup();   // safe to call on every job or test
// ... handle the job ...
Tokentify::flush();         // send queued usage now (also done automatically when the process ends)
```

In long-running processes (Laravel Octane, RoadRunner, Swoole, queue workers), call `flush()` after each job or
request, and restore tag scopes (use `withTags()`) so tags don't leak into the next request.

## SDK or Gateway?

Tokentify can also be used through its **Gateway** (you point your client at the Tokentify proxy instead of the
provider). The two are configured differently:

| | SDK (this package) | Gateway |
|---|---|---|
| Your request goes to | the provider directly | the Tokentify proxy |
| You set user, bucket and dimensions with | `withTags()`, `tag()`, `session()`, `.env` | `X-Tokentify-*` headers |

**`X-Tokentify-*` headers are for the Gateway only.** The SDK does not read them. If you set one on a call to a
provider, the SDK removes it before sending, so it never reaches the provider, and it is not recorded. Use tags
instead:

```php
// Gateway style: not used by the SDK
$http->post($url, ['headers' => ['X-Tokentify-User-Id' => 'u_123'], ...]);

// SDK style
Tokentify::withTags(['user_id' => 'u_123'], fn () => $http->post($url, [...]));
```

## Upgrading from `tokentifyai/usagemeter` (0.4.x)

```bash
composer remove tokentifyai/usagemeter
composer require tokentify/tokentify-sdk
```

Existing code keeps working: `UsageMeter\Meter`, `UsageMeter\Tokentify` and the other `UsageMeter\` classes are
still available (deprecated) and call the new SDK.

- Your `USAGEMETER_*` `.env` variables are still read. If both are set, the `TOKENTIFY_*` names win.
- **`api_key` can no longer be passed to `init()`.** Put `TOKENTIFY_API_KEY` in `.env`.
- The default collector is now `http://api.tokentify.ai`.
- `feature` and `customer_id` are no longer recorded (use `user_id` / `account_id`). OpenAI cached tokens are now
  billed correctly, so the stored `input_tokens` is OpenAI's full `prompt_tokens`.
- New: automatic tracking (`guzzleMiddleware()`, `httpClient()`), streaming, scoped tags, `session()`,
  `agentRun()` / `tool()`, speech, provider buckets.

When convenient, switch to `use Tokentify\Tokentify;` and the methods above.

## Good to know

- **The first tracked call starts tracking on its own** when `TOKENTIFY_API_KEY` is set and you never called
  `setup()`. It never throws: if the key is missing, tracking stays off and a warning is logged.
  `Tokentify::enabled()` tells you whether tracking is on. Calling `setup()` adds your bucket and checks the API key.
- **`setup()` throws** if the API key is invalid or the collector cannot be reached (when `TOKENTIFY_VERIFY=true`).
  Set `TOKENTIFY_VERIFY=false` to skip the check.
- **Sending is synchronous** (PHP has no background thread): a batch is posted when 50 events are queued, on
  `flush()`, and when the process ends. Failed batches are retried on 429 / 5xx and then dropped, never thrown,
  unless you pass `failOnDeliveryError: true`.
- **Short scripts** need nothing extra. Queued usage is sent at exit. If your process is killed or calls
  `exit()` from a signal handler, call `Tokentify::flush()` first.

## Troubleshooting

| Problem | Check |
|---------|-------|
| Nothing in the dashboard | `var_dump(Tokentify::enabled())` should be `true`. Check `TOKENTIFY_API_KEY` in `.env`. |
| Calls not tracked | The client must have `Tokentify::guzzleMiddleware()` (or be wrapped with `httpClient()`), and the provider must be in the list above. |
| OpenAI streams have no tokens | Add `'stream_options' => ['include_usage' => true]`, and read the stream to the end. |
| Custom dimension missing | Declare it on the bucket in the dashboard. |
| Need more detail | Set `TOKENTIFY_DEBUG=true`. |

## Links

- Documentation: http://tokentify.ai/docs
- Changelog: [CHANGELOG.md](CHANGELOG.md)
- Other languages (Python, Node.js, Java, .NET): http://tokentify.ai/docs#sdk-languages

## Development

```bash
git clone https://github.com/infinistackai/tokentify-sdk-php
cd tokentify-sdk-php
composer install
cp .env.example .env
composer test
```

## License

MIT
