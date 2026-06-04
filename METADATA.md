# Model-call metadata and the PHP SDK

This document answers: **can billing metadata come from each AI call instead of `Tokentify::init()`?**

**Yes for values.** `Tokentify::init()` (and `Meter::init()`) configure **which metadata key names** map to `group_key_1` / `group_value_1`, … (`tracking_fields`). With **`Tokentify::init()`**, you **must** pass **`tracking_fields`** explicitly (second argument and/or options); the list **must** include the exact keys **`account_id`** and **`user_id`**. Init does **not** carry the **values** of those keys per request. You supply values **when you meter**, using the same array you already build for the vendor (or a subset), on `Meter::track()`, **`Meter::trackWithVendorMetadata()`**, or via `Meter::tag()`.

---

## What belongs in `init()` vs on each call

| Concern | Where it goes | Example |
|--------|----------------|---------|
| Ordered list of **keys** that map to `group_key_1` / `group_value_1`, … | `Tokentify::init('bucket', ['account_id', 'user_id', ...])` — required `'tracking_fields'`; must include **`account_id`** and **`user_id`** | Declares schema only |
| **Values** for those keys (and any extra metadata) | `Meter::track([ ..., 'metadata' => [ 'account_id' => '…', 'user_id' => '…' ] ])` and/or `Meter::tag([...])` | Per call or per request scope |

So you do **not** pass model-call metadata “through init” as values—only the **names** in `tracking_fields`. Your PHP project can keep building one metadata array for the provider and pass the same structure into UsageMeter on `track()`.

---

## Recommended: reuse the same metadata array

If you already have something like:

```php
$modelMetadata = [
    'account_id' => $accountId,
    'user_id'    => $userId,
    // optional: other scalar fields you want on the ingest row
];
```

Use it for the vendor request **and** for metering (names must match your effective `tracking_fields` — default `account_id`, `user_id`):

```php
Meter::track([
    'provider'       => 'openai',
    'model'          => $model,
    'input_tokens'   => $inputTokens,
    'output_tokens'  => $outputTokens,
    'metadata'       => $modelMetadata,
]);

// Or merge in one call:
Meter::trackWithVendorMetadata(
    [
        'provider'      => 'openai',
        'model'         => $model,
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
    ],
    $modelMetadata
);
```

The SDK merges `Meter::tag()` values with per-call `metadata` (call-specific keys override tags for the same key). It also always adds `environment` from init/env into the merged metadata sent to the collector.

---

## When to use `Meter::tag()` instead

Use `Meter::tag()` when many `track()` calls in the same HTTP request share the same tenant/user and you do not want to repeat `metadata` on every `track()`:

```php
Meter::tag($modelMetadata);
Meter::track([ 'provider' => 'openai', 'model' => $model, 'input_tokens' => $in, 'output_tokens' => $out ]);
```

Tags persist in memory for the process until overwritten; scope them to “this request” in your framework (e.g. set in middleware, clear if needed).

---

## Strict validation

If you enable `strict_tracking_validation` in init, every `track()` / `trackSms()` must include **all** `tracking_fields` keys in the merged metadata (from `tag()` + `metadata`). Missing keys throw `ValidationException`. With strict mode off, missing keys still produce ingest rows but corresponding `group_value_i` may be empty.

---

## What the SDK does *not* do

- It does **not** read metadata back out of the vendor HTTP response for you. You pass the metadata you want stored (typically the same structure you sent on the model call, or IDs resolved server-side).
- It does **not** expose a “get last event metadata” API; the flow is **your app → `Meter::track()` → collector**.

---

## See also

- [`INTEGRATION.md`](./INTEGRATION.md) — end-to-end PHP integration, `account_id` / `user_id` rules, and env vars.
- [`README.md`](./README.md) — install, options, and more examples.
