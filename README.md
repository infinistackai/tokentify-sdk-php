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

Copy `.env.example` to `.env`. The **API key lives only in `.env`** (or your deployment environment)—not in application `init()` code.

```bash
cp .env.example .env
```

```bash
# .env — required
USAGEMETER_API_KEY=your_ingest_api_key
USAGEMETER_BUCKET=my-bucket

# optional (SDK default if unset)
USAGEMETER_COLLECTOR_URL=http://205.209.126.182:8006
```

| Variable | Required | Where to set |
|----------|----------|----------------|
| `USAGEMETER_API_KEY` | **Yes** | `.env` only (global secret) |
| `USAGEMETER_BUCKET` | Yes* | `.env` or `init()` bucket argument |
| `USAGEMETER_COLLECTOR_URL` | No | Default `http://205.209.126.182:8006`; override in `.env` |
| `USAGEMETER_ENVIRONMENT` | No | `.env` (default `production`) |
| `USAGEMETER_APP_NAME` | No | `.env` |

\*Bucket: set in `.env` **or** pass to `Tokentify::init()` / `Meter::init()`.

API key aliases: `UM_API_KEY`, `USAGEMETER_TOKEN`, `UM_TOKEN`.

When [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv) is installed, `init()` loads `.env` from the working directory automatically (`load_env_file` default `true`). Set `load_env_file => false` in frameworks that already load env vars.

### 2. Initialize

Use **`Tokentify::init()`** (recommended). It requires **`tracking_fields`** including **`account_id`** and **`user_id`** for flat `group_key_*` / `group_value_*` metadata (same model as the Python/Node SDKs).

```php
<?php

use UsageMeter\Tokentify;
use UsageMeter\Meter;

require __DIR__ . '/vendor/autoload.php';

// API key from .env; bucket + tracking fields from arguments
Tokentify::init('my-bucket', ['account_id', 'user_id']);

// API key and bucket both from .env
Tokentify::init(getenv('USAGEMETER_BUCKET') ?: 'my-bucket', ['account_id', 'user_id']);

// Production (skip health checks after first successful setup)
Tokentify::init('my-bucket', ['account_id', 'user_id'], [
    'verify_connection' => false,
    'verify_api_key' => false,
]);
```

| Call | When to use |
|------|-------------|
| `Tokentify::init($bucket, $trackingFields, ['verify_connection' => true, 'verify_api_key' => true])` | First run; verifies collector + API key from `.env` (like Python `setup()`) |
| `Tokentify::init($bucket, $trackingFields)` | Same; `verify_connection` default `false`, `verify_api_key` default `true` |

**Init parameters** (prefer API key from env, not in code):

| Parameter | Source |
|-----------|--------|
| `bucket` | First argument or `USAGEMETER_BUCKET` in `.env` |
| `tracking_fields` | Second argument (must include `account_id`, `user_id`) |
| `app_name` | Third-argument merge array or `.env` |
| `environment` | Merge array or `.env` (default `production`) |
| `collector_url` | Merge array or `.env` |
| `load_env_file` | Default `true` when phpdotenv is installed |
| `verify_connection` | Default `false` (`true` for first-time setup) |
| `verify_api_key` | Default `true` |

Lower-level **`Meter::init([...])`** is available without `tracking_fields` for legacy or custom metadata layouts.

Call `init()` **before** sending usage events.

### 3. Launch (v0.1.1)

`.env` (API key + bucket):

```bash
USAGEMETER_API_KEY=your_ingest_api_key
USAGEMETER_BUCKET=my-bucket
```

`app.php`:

```php
<?php

use UsageMeter\Tokentify;
use UsageMeter\Meter;

require __DIR__ . '/vendor/autoload.php';

Tokentify::init(getenv('USAGEMETER_BUCKET') ?: 'my-bucket', ['account_id', 'user_id']);
// loads .env when phpdotenv is installed; API key from USAGEMETER_API_KEY only

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

// Record usage after the provider call (PHP does not auto-instrument HTTP)
Meter::track([
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'input_tokens' => 10,
    'output_tokens' => 5,
    'http_status' => $status,
    'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
]);

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
Tokentify::init(getenv('USAGEMETER_BUCKET') ?: 'my-bucket', ['account_id', 'user_id'], ['verify_api_key' => false]);
echo \"ok\n\";
"
```
