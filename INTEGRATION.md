# Tokentify / UsageMeter — PHP API integration (Cursor handoff)

Use this document as the **single source of truth** when wiring a PHP backend to the UsageMeter collector and the `tokentifyai/usagemeter` package. Paste it into Cursor (or attach `@sdk/php/INTEGRATION.md`) so the agent implements **ingest + attribution** without guessing.

---

## Goal

After every billable **AI model** call (OpenAI, Anthropic, Gemini, or any provider you call from PHP):

1. **Record usage** with `Meter::track()` (token counts from the vendor response).
2. **Attribute that row** to exactly one tenant and one end-user using the metadata keys **`account_id`** and **`user_id`** (same names, same values, every time). `Tokentify::init()` **must** declare a `tracking_fields` list that **includes both keys** (exact spellings); you may add more keys after them for extra grouping slots.

The collector stores flat rows. `Tokentify::init()` declares **ordered** tracking keys; the SDK denormalizes them into `group_key_1` / `group_value_1` and `group_key_2` / `group_value_2` inside JSON `metadata` for analytics and **`GET /v1/usage/events?account_id=...`** totals.

---

## Step-by-step integration

Follow these steps in order. Skip optional steps unless you need them.

### Step 1 — Install the SDK

In your application root (where `composer.json` lives):

```bash
composer require tokentifyai/usagemeter
```

Requirements: **PHP 8.1+**, **`ext-json`**. For production HTTP to the collector, install **`ext-curl`** (recommended).

---

### Step 2 — Configure environment variables

For **`Tokentify::init()`**, treat **three things** as mandatory for a correct integration (the SDK enforces the third in code; the first two match how `Meter::init()` resolves config):

1. **API key** — env (`USAGEMETER_API_KEY` or aliases) **or** `'api_key'` in the legacy options array.
2. **Bucket name** — first string argument to `Tokentify::init('…')`, **or** `'bucket' => '…'` in the options array, **or** a non-empty `USAGEMETER_BUCKET` when the merged options leave `bucket` empty (legacy array + env fallback).
3. **`tracking_fields`** — **explicit** ordered list passed as the **second** argument and/or `'tracking_fields'` in the options / third merge array. It **must** include the exact key names **`account_id`** and **`user_id`** (you may list additional keys for more `group_key_*` slots). Omitting `tracking_fields` or omitting either required key throws `ConfigurationException` (`invalid_tracking_fields`).

| Variable | Required | Notes |
|----------|----------|--------|
| `USAGEMETER_API_KEY` | Yes* | Bearer token for `POST /v1/ingest` (aliases: `UM_API_KEY`, `USAGEMETER_TOKEN`, `UM_TOKEN`) |
| `USAGEMETER_BUCKET` | Yes* | Logical bucket name on events, if not passed as the first argument to `Tokentify::init('…', …)` or as `'bucket'` in the options array |
| `USAGEMETER_COLLECTOR_URL` | No | Base URL only, no path (legacy: `COLLECTOR_URL`). Default: `http://localhost:8000` |
| `USAGEMETER_ENVIRONMENT` | No | e.g. `staging` (default: `production`) |

\*You may pass `api_key` in the **legacy** options array instead of using env vars. The SDK reads the key from **`getenv()`**; frameworks like Laravel normally populate that from `.env` on boot.

**Bucket is mandatory somewhere:** `Tokentify::init('my-bucket', …)` / `'bucket' => '…'` in the options array, **or** a non-empty `USAGEMETER_BUCKET` when using a legacy options array with an empty `bucket` key. If the resolved bucket is empty, the SDK throws `ConfigurationException` (`missing_bucket`).

---

### Step 3 — Initialize once (`Tokentify::init`)

Call **`Tokentify::init()`** once per PHP process or once per HTTP request—whichever matches how you scope **`Meter::tag()`** (request-scoped tags are stored in static SDK state).

#### 3a — Recommended: Python-style (bucket string + explicit `tracking_fields` + env for secrets)

Same idea as the Python SDK: pass the **dashboard bucket name** as the first string; pass **`tracking_fields`** as the second argument (required; must include **`account_id`** and **`user_id`**). **API key** and **collector URL** usually come from the environment (and optional `.env` load—see Step 3c).

```php
<?php

declare(strict_types=1);

use UsageMeter\Tokentify;
use UsageMeter\Meter;

require __DIR__ . '/vendor/autoload.php';

// Required second argument; canonical order is account_id then user_id (swap only if you intend to flip group_key slots)
Tokentify::init('my-app', ['account_id', 'user_id']);
```

With **extra** tracking keys after the two required ones (order controls every `group_key_i` slot):

```php
Tokentify::init('my-app', ['account_id', 'user_id', 'session_id']);
```

#### 3b — Optional third argument (merge flags)

The third parameter is merged into the resolved options **after** the bucket string or options array. Use it for Laravel/Symfony (framework already loaded `.env`) or to tune verification. You can supply **`tracking_fields` here instead of the second argument** (second argument may be `null` only when the merged options define `tracking_fields`):

```php
Tokentify::init('my-app', ['account_id', 'user_id'], [
    'load_env_file' => false,   // Laravel: usually false — avoid double-loading .env
    'verify_api_key' => false,  // optional: skip POST /v1/ingest probe on boot (default is true)
    'verify_connection' => false, // optional: skip GET /health on boot (default is false)
]);
```

Merge order: **`array_merge($extraOptions, $bucketOrOptions)`** when the first argument is an array, so keys in the **options array win** over the third argument on conflicts. When the first argument is a **string**, the bucket from the string always wins over any `'bucket'` in the third array.

#### 3c — `.env` loading (vanilla PHP vs Laravel)

- If **`vlucas/phpdotenv`** is installed and **`load_env_file`** is not set to **`false`**, the SDK will try to **`safeLoad()`** a `.env` from sensible working-directory candidates (see `README.md`).
- **Laravel** (and similar): the framework already loads `.env` into the process. Pass **`'load_env_file' => false`** in the third argument (or inside a legacy options array) so the SDK does not load `.env` again.

#### 3d — Legacy: full options array (still supported)

Use this when you want every knob in one structure, or to pass **`api_key`** explicitly instead of env:

```php
Tokentify::init([
    'api_key' => '…',
    'bucket' => 'my-app',
    'tracking_fields' => ['account_id', 'user_id'],
    'collector_url' => 'https://collector.example.com',
    'verify_api_key' => true,
    'load_env_file' => false,
]);
```

You can combine **legacy array + second-argument tracking override** (second argument wins on conflict; it must still include **`account_id`** and **`user_id`**):

```php
Tokentify::init(
    [
        'api_key' => '…',
        'bucket' => 'my-app',
        'tracking_fields' => ['account_id', 'user_id'],
        'load_env_file' => false,
    ],
    ['account_id', 'user_id', 'tenant_id'] // overrides: adds tenant_id as third grouping key
);
```

---

### Step 4 — Attach tenant and user for each request

Before **`Meter::track()`** (or on each call), ensure metadata contains the keys declared in **`tracking_fields`** (always includes **`account_id`** and **`user_id`** for `Tokentify::init()`). Resolve IDs from **auth/session**—never trust raw client input alone without verification.

Typical pattern:

```php
Meter::tag([
    'account_id' => $accountId,
    'user_id'    => $userId,
]);
```

Alternatives: **`'metadata' => [...]`** on `Meter::track()`, or **`Meter::trackWithVendorMetadata()`** with the same array you pass to the vendor’s request metadata. See **Section: After each AI call** below.

---

### Step 5 — Record usage after the vendor returns

Map the provider’s usage object into **`input_tokens`** / **`output_tokens`** (and optional cache fields if exposed), then call **`Meter::track()`** on **success and on failure paths** where you still want usage or failed-call telemetry.

---

### Step 6 — Flush when the process needs an explicit send

The SDK batches events (e.g. flushes on shutdown). In **workers**, **queues**, or **long-lived requests**, call **`Meter::flush()`** after a unit of work so events are not held only in memory.

---

### Step 7 — (Optional) Strict metadata validation

To **throw** if tracking keys are missing from merged metadata (instead of ingesting empty `group_value_*` slots), enable strict mode via the third merge argument or the legacy array:

```php
Tokentify::init('my-app', ['account_id', 'user_id'], ['strict_tracking_validation' => true, 'load_env_file' => false]);
```

---

## Advanced: `Meter::init()` without `Tokentify`

`Meter::init()` does **not** require `tracking_fields`. Omitting them skips SDK-side `group_key_*` enrichment; you can still send plain `account_id` / `user_id` on `metadata`. Prefer **`Tokentify::init()`** when you want the Tokentify / dashboard grouping shape.

---

## Rule: `account_id` and `user_id` on every AI path

| Responsibility | What to do |
|----------------|------------|
| **Metering (required)** | Before or when calling `Meter::track()`, ensure **`account_id`** and **`user_id`** are present in merged metadata: `Meter::tag([...])`, and/or `'metadata' => [...]` on that `track()` call, and/or **`Meter::trackWithVendorMetadata($trackOptions, $vendorMetadata)`** with the same dict you pass to the vendor’s request `metadata`. Key names must match your effective `tracking_fields` (for `Tokentify::init()`, that list **always** includes lowercase `account_id` and `user_id`, plus any extra keys you added). |
| **Your HTTP API response (recommended for frontend + Cursor)** | Any JSON your PHP app returns to a **frontend** after an AI completion should **also include** `account_id` and `user_id` (same strings you used for metering). |
| **Vendor “model output” text** | Do **not** rely on the LLM’s free-form reply to carry billing IDs. IDs belong in **your** structured API payload and in **`Meter::tag()` / `metadata`** on `Meter::track()`. |

Optional: if you use **structured outputs** for app logic, you may still add `account_id` and `user_id` as **server-filled echo fields** after the model returns.

---

## After each AI call: track tokens + IDs

### Option A — Request-scoped tags (good when one tenant/user per request)

```php
$accountId = '659cc809-1f7e-4e00-81f7-85e02dff6849';
$userId    = '00000000-0000-4000-8000-000000000001';

Meter::tag([
    'account_id' => $accountId,
    'user_id'    => $userId,
]);

Meter::track([
    'provider'       => 'openai',
    'model'          => 'gpt-4.1',
    'input_tokens'   => $inputTokens,
    'output_tokens'  => $outputTokens,
    'endpoint'       => '/v1/chat/completions',
    'status'         => 'success',
]);

Meter::flush(); // workers / long requests: optional explicit send
```

### Option B — Same metadata array as the vendor (architecture doc)

```php
$modelMetadata = [
    'account_id' => $accountId,
    'user_id'    => $userId,
];

Meter::track([
    'provider'       => 'openai',
    'model'          => $model,
    'input_tokens'   => $inputTokens,
    'output_tokens'  => $outputTokens,
    'metadata'       => $modelMetadata,
]);
```

### Option C — `Meter::trackWithVendorMetadata()` (merge helper)

```php
Meter::trackWithVendorMetadata(
    [
        'provider'      => 'openai',
        'model'         => $model,
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
        'endpoint'      => '/v1/chat/completions',
        'status'        => 'success',
    ],
    $modelMetadata
);
```

If you also pass `'metadata' => [...]` inside the first array, those keys **override** the same keys from `$modelMetadata`.

---

## Collector HTTP (what the SDK calls)

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/v1/ingest` | Body `{"events":[...]}` — written by the SDK after `Meter::track()` |
| `GET` | `/health` | Optional connectivity check when `verify_connection` is `true` on init |
| `GET` | `/v1/usage/events?account_id=<uuid>` | **Not in SDK** — your code; same Bearer key; response includes totals when filtered by `account_id` |

Authorization: `Authorization: Bearer <USAGEMETER_API_KEY>`.

---

## Example: JSON your API returns to the frontend (after AI)

Keep metering on the server; expose IDs so the client stays consistent with ingest rows:

```json
{
  "reply": "Assistant text …",
  "account_id": "659cc809-1f7e-4e00-81f7-85e02dff6849",
  "user_id": "00000000-0000-4000-8000-000000000001",
  "usage": {
    "input_tokens": 10,
    "output_tokens": 5
  }
}
```

Implement the PHP handler so **`Meter::track()` uses the same `$accountId` / `$userId`** as in this JSON.

---

## Cursor agent checklist

When implementing or refactoring from this file, the agent should:

1. **Install** — `composer require tokentifyai/usagemeter` in the app.
2. **Env** — Ensure `USAGEMETER_API_KEY` (or aliases) is set; ensure a **bucket** is set via **`Tokentify::init('bucket', ['account_id','user_id'])`** or **`USAGEMETER_BUCKET`** / `'bucket'` when using a legacy options array.
3. **Init** — Call **`Tokentify::init()`** once per process/request with **mandatory** **`tracking_fields`** (including **`account_id`** and **`user_id`**), plus bucket (string or `'bucket'` / env as above). Prefer env for the API key; use **`['load_env_file' => false]`** in the third argument on Laravel; use the **legacy array** only when you need explicit `api_key` / `collector_url` in code.
4. **Attribution** — On every path that calls `Meter::track()` after an AI call, supply values for every key in **`tracking_fields`** (always including **`account_id`** and **`user_id`** for `Tokentify::init()`) via **`Meter::tag()`**, **`metadata`**, and/or **`Meter::trackWithVendorMetadata()`** (including failure paths where you still meter).
5. **Tokens** — Map vendor usage into **`input_tokens`** / **`output_tokens`** (and optional cache fields).
6. **Responses** — Include **`account_id` and `user_id`** in JSON returned to the frontend when returning AI results (recommended).
7. **Flush** — Use **`Meter::flush()`** in workers/queues/long requests when needed.

For more PHP examples and metadata behavior, see **`README.md`** and **`METADATA.md`** in this same directory.
