# Changelog

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
