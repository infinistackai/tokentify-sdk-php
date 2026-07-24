# Tokentify UsageMeter (PHP)

Out-of-band AI API usage metering for PHP. After setup, call your provider’s HTTP API yourself, then record usage with `Meter::track()` (the PHP SDK does not auto-wrap HTTP clients).

## Install

```bash
composer require tokentifyai/usagemeter
```

Optional: load `.env` in development (recommended):

```bash
composer require vlucas/phpdotenv
```

## Setup

### 1. Environment (API key is global)

Copy `.env.example` to `.env`. The **API key lives only in `.env`** (or your deployment environment)—never in `init()` code.

```bash
cp .env.example .env
```

```bash
# .env — required
USAGEMETER_API_KEY=your_ingest_api_key
USAGEMETER_BUCKET=your_bucket_name
```

### 2. Initialize

```php
<?php

use UsageMeter\Tokentify;

require __DIR__ . '/vendor/autoload.php';

// USAGEMETER_API_KEY from .env; USAGEMETER_BUCKET from argument
Tokentify::init('your_bucket_name', ['account_id', 'user_id']);

// USAGEMETER_API_KEY and USAGEMETER_BUCKET both from .env
Tokentify::init(getenv('USAGEMETER_BUCKET') ?: 'your_bucket_name', ['account_id', 'user_id']);

// Production (skip health checks after first successful setup)
Tokentify::init(getenv('USAGEMETER_BUCKET') ?: 'your_bucket_name', ['account_id', 'user_id'], [
    'verify_connection' => false,
    'verify_api_key' => false,
]);
```

| Call | When to use |
|------|-------------|
| `Tokentify::init(..., ['verify_connection' => true, 'verify_api_key' => true])` | First run; verifies collector + `USAGEMETER_API_KEY` from `.env` |
| `Tokentify::init(...)` | Same; control `verify_connection` / `verify_api_key` |

**Init parameters** (API key is not accepted):

| Parameter | Source |
|-----------|--------|
| `bucket` | First argument (value of `USAGEMETER_BUCKET`) or `USAGEMETER_BUCKET` in `.env` |
| `tracking_fields` | Second argument (must include `account_id`, `user_id`) |
| `app_name` | Third-argument merge array or `USAGEMETER_APP_NAME` in `.env` |
| `environment` | Merge array or `USAGEMETER_ENVIRONMENT` in `.env` (default `production`) |
| `load_env_file` | Default `true` when phpdotenv is installed — loads `.env` before reading env vars |
| `verify_connection` | Default `false` (set `true` for first-time setup) |
| `verify_api_key` | Default `true` |

```php
// Not supported — keep the API key in .env only:
// Tokentify::init(['api_key' => 'secret', 'bucket' => 'x', 'tracking_fields' => ['account_id', 'user_id']]);
```

Call `init()` **before** provider HTTP calls and `Meter::track()` events.

## Agent tool tracking

After your app executes a tool (any provider / agent framework), call
`Meter::trackTool()` once:

```php
Meter::trackTool([
    'trace_id' => 'tr_abc',
    'span_id' => 'sp_1',
    'tool_name' => 'get_weather',
    'tool_input' => ['city' => 'Paris'],
    'tool_output' => ['temp_c' => 22],
    'status' => 'success',
    'duration_ms' => 42,
]);
```

Provider APIs expose tool-call data in different places (response content vs
client-side MCP loops; never in billing `usage`). See the
[provider research matrix](https://github.com/infinistackai/tokentify-backend/blob/main/docs/AfterDemo/04-tool-usage-tracking.md#provider-research--where-tool-call-data-lives)
before adding auto-capture.

## Token breakdown (v0.4.0)

The SDK extracts **input**, **output**, **cache read**, and **cache write** tokens from provider JSON via `UsageParser`.

### Recommended — `trackFromResponse`

```php
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

$usage = Meter::trackFromResponse(
    [
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'http_status' => $status,
        'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
    ],
    is_string($response) ? $response : '',
);
```

### Alternative — `track(['response_body' => ...])`

```php
Meter::track([
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'response_body' => $response,
]);
```

### Field mapping

| Ingest field | Anthropic source | OpenAI source |
|--------------|------------------|---------------|
| `input_tokens` | `usage.input_tokens` | `prompt_tokens − cached_tokens` |
| `output_tokens` | `usage.output_tokens` | `usage.completion_tokens` |
| `cache_read_tokens` | `usage.cache_read_input_tokens` | `usage.prompt_tokens_details.cached_tokens` |
| `cache_write_tokens` | `usage.cache_creation_input_tokens` | — |

### Anti-pattern

```php
// ❌ drops cache_read_tokens
Meter::track([
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'input_tokens' => $usage['prompt_tokens'],
    'output_tokens' => $usage['completion_tokens'],
]);
```

### 3. Launch (v0.4.0)

`.env` (`USAGEMETER_API_KEY` + `USAGEMETER_BUCKET`):

```bash
USAGEMETER_API_KEY=your_ingest_api_key
USAGEMETER_BUCKET=your_bucket_name
```

`app.php`:

```php
<?php

use UsageMeter\Tokentify;
use UsageMeter\Meter;

require __DIR__ . '/vendor/autoload.php';

Tokentify::init(getenv('USAGEMETER_BUCKET') ?: 'your_bucket_name', ['account_id', 'user_id']);
// loads .env when phpdotenv is installed; reads USAGEMETER_API_KEY and USAGEMETER_BUCKET

Meter::tag([
    'account_id' => '00000000-0000-4000-8000-000000000001',
    'user_id' => '00000000-0000-4000-8000-000000000002',
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer YOUR_OPENAI_KEY',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ]),
]);
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

Meter::trackFromResponse(
    [
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'http_status' => $status,
        'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
    ],
    is_string($response) ? $response : '',
);

Meter::flush();
```

```bash
php app.php
```

### 4. Develop from this repo

```bash
cd tokentify-sdk-php
composer install
cp .env.example .env   # set USAGEMETER_API_KEY and USAGEMETER_BUCKET
composer test
```

Quick init check:

```bash
php -r "
require 'vendor/autoload.php';
use UsageMeter\Tokentify;
Tokentify::init(getenv('USAGEMETER_BUCKET') ?: 'your_bucket_name', ['account_id', 'user_id'], [
    'verify_api_key' => false,
    'load_env_file' => false,
]);
echo \"ok\n\";
"
```
