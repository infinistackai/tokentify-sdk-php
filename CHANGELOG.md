# Changelog

## Unreleased

### Changed (events now match the gateway's)

- **Failed AI calls are recorded.** A call to an LLM endpoint that fails before returning usage (a 4xx/5xx
  response, or a connection error / timeout) is now sent as an event with `status: "error"`, `http_status`
  (absent for connection errors), `error_code`, 0 tokens and `usage_source: "none"`, with the same user, account,
  session and tags as a successful call. This works through both `Tokentify::guzzleMiddleware()` and
  `Tokentify::httpClient()`. Before, these calls were dropped, so rate-limited or rejected traffic never reached
  the dashboard while the same traffic through the gateway did. `error_code` comes from the provider's error
  body (OpenAI `error.code` or `error.type`, Anthropic `error.type`, Gemini `error.status`; else
  `http_<status>`; for connection errors the exception's short class name, e.g. `ConnectException`); never the
  error message. Exceptions and rejected promises reach your code unchanged. Non-LLM calls (`GET /v1/models`,
  files, ...) are still not recorded.
- **`endpoint` is sent in the gateway's form** (`/v1/chat/completions`, `/v1/messages`, `/v1/embeddings`,
  `/v1/responses`, `/v1/completions`, `/v1/audio/transcriptions|translations|speech`), in the event and in
  `metadata.endpoint`. Bucket routing rules on `endpoint` (e.g. `starts_with /v1/embeddings`) now match SDK
  traffic. Short names passed to `track()` / `trackSpeech()` (`endpoint: 'messages'`) are converted the same
  way; other values are sent unchanged.
- **`usage_source`** is sent on LLM events, as the gateway does: `reported` when token counts were given,
  `none` when there were none.

## 0.5.0

Brings the PHP SDK to parity with the Python SDK 0.5.0. Events sent to the collector have the same shape as
the Python SDK's.

### Added

- **New package and namespace:** `tokentify/tokentify-sdk`, `Tokentify\Tokentify`.
- **Automatic tracking** of AI calls: `Tokentify::guzzleMiddleware()` for Guzzle and `Tokentify::httpClient()`
  for any PSR-18 client. Covers OpenAI, Azure OpenAI, Anthropic, Google Gemini, AWS Bedrock, Cohere, Mistral,
  Groq, Perplexity, Fireworks and OpenRouter, including **streaming** (SSE) responses, recorded once per stream.
  The model is read from the request body, or from the URL for Gemini / Bedrock. Tool calls are tagged
  (`tool_names`, `tool_call_count`). OpenAI speech endpoints are billed in seconds / characters.
- `X-Tokentify-*` headers are removed from requests to AI providers (they are Gateway-only).
- `Tokentify::loadEnv()` (built-in `.env` reader, never overrides existing variables), `setup()`,
  `ensureSetup()`, and auto-enable on the first tracked call (`TOKENTIFY_AUTO_SETUP`, never throws).
- Scoped attribution: `tag()` returns a `TagScope`, `withTags()`, `session()` (optionally ending the session's
  context-window state), and per-call `user_id` / `account_id` / `session_id` / `trace_id`. OpenAI's request
  `user` field becomes `user_id`.
- Agents: `agentRun()` (`call()`, `record()`, `run()`) and `tool()` wrappers with trace / span ids.
- `track()` for custom (non-LLM) usage; `trackSpeech()` / `trackFromSpeechResponse()`.
- `providerBuckets` / `TOKENTIFY_PROVIDER_BUCKETS` to route each provider to its own bucket.
- `trackFromResponse()` accepts a JSON string, array, PSR-7 response or an object with `toArray()`.
- Cache tokens parsed for every provider format from one table (Gemini, Bedrock, DeepSeek-style included).
- Emitter: retries 429 / 5xx with backoff until the flush deadline, flushes at exit, `sendBatch()` for callers
  with their own queue, API key check (`401` / `403` → clear "invalid or revoked API key" error).
- `TOKENTIFY_*` environment variables (`USAGEMETER_*` still read as fallbacks).

### Changed

- Default collector is `http://api.tokentify.ai`.
- **`api_key` can no longer be passed to `init()` / `setup()`.** Set `TOKENTIFY_API_KEY` in `.env`.
- OpenAI-family events carry the full `prompt_tokens` as `input_tokens` (cached included), as the Gateway does,
  so the collector removes the cache once. The returned `TokenUsage` still excludes cached tokens.
- `customer_id` and `feature` are no longer recorded. Use `user_id` / `account_id`.
- Exception messages start with `tokentify:`.

### Deprecated

- `UsageMeter\Meter` and `UsageMeter\Tokentify` now delegate to `Tokentify\Tokentify`. Other `UsageMeter\`
  classes are aliases of their `Tokentify\` counterparts.
