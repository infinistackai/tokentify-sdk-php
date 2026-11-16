<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * One agent run with a stable trace id: SDK-owned agent tool metering (trace / span ids, duration, ingest emit).
 *
 *     $run = Tokentify::agentRun();
 *     $weather = $run->call('get_weather', ['city' => 'Paris'], fn () => fetchWeather('Paris'));
 *
 * Use run() to make it the active run, so {@see Tokentify::tool()} wrappers called inside share its trace id.
 */
final class AgentRun
{
    public readonly string $traceId;

    /** @var list<AgentRun> */
    private static array $stack = [];

    public function __construct(?string $traceId = null, public readonly ?string $parentSpanId = null)
    {
        $id = trim((string) $traceId);
        $this->traceId = $id !== '' ? $id : self::newTraceId();
    }

    /**
     * Make this the active run while $fn runs (Python's `with tokentify.agent_run() as run:`).
     *
     * @template T
     * @param callable(AgentRun): T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        self::$stack[] = $this;
        try {
            return $fn($this);
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * Execute $fn with metering and return its result. Exceptions are recorded (status `error`) and re-thrown.
     */
    public function call(string $toolName, mixed $toolInput, callable $fn, ?string $parentSpanId = null): mixed
    {
        return self::executeTrackedTool(
            $toolName,
            $toolInput,
            $fn,
            $this->traceId,
            $parentSpanId ?? $this->parentSpanId,
        )['result'] ?? null;
    }

    /**
     * Meter an already-executed tool (e.g. hosted OpenAI tools).
     *
     * @return array<string, mixed> Payload with `sent` (bool) or `send_error`
     */
    public function record(
        string $toolName,
        mixed $toolInput,
        mixed $toolOutput,
        string $status,
        int $durationMs = 0,
        ?string $parentSpanId = null,
    ): array {
        return self::recordToolEvent(
            toolName: $toolName,
            toolInput: $toolInput,
            toolOutput: $toolOutput,
            status: $status,
            durationMs: $durationMs,
            traceId: $this->traceId,
            parentSpanId: $parentSpanId ?? $this->parentSpanId,
        );
    }

    /** The active agent-run trace id, if any. */
    public static function currentTraceId(): ?string
    {
        $run = end(self::$stack);

        return $run instanceof self ? $run->traceId : null;
    }

    public static function newTraceId(): string
    {
        return 'tr_' . bin2hex(random_bytes(8));
    }

    public static function newSpanId(): string
    {
        return 'sp_' . bin2hex(random_bytes(6));
    }

    /**
     * Run $fn and emit one tool ingest event. Returns a UI-friendly payload (`result`, `sent`, ids, duration).
     * On an exception, records status `error` with `tool_output` `['error' => message]` and re-throws.
     *
     * @return array<string, mixed>
     */
    public static function executeTrackedTool(
        string $toolName,
        mixed $toolInput,
        callable $fn,
        ?string $traceId = null,
        ?string $parentSpanId = null,
        ?string $accountId = null,
        ?string $userId = null,
        ?callable $trackToolFn = null,
    ): array {
        $resolvedTrace = self::resolveTrace($traceId);
        $t0 = hrtime(true);
        try {
            $output = $fn();
        } catch (\Throwable $e) {
            self::recordToolEvent(
                toolName: $toolName,
                toolInput: $toolInput,
                toolOutput: ['error' => $e->getMessage()],
                status: 'error',
                durationMs: self::elapsedMs($t0),
                traceId: $resolvedTrace,
                parentSpanId: $parentSpanId,
                accountId: $accountId,
                userId: $userId,
                trackToolFn: $trackToolFn,
            );
            throw $e;
        }

        return self::recordToolEvent(
            toolName: $toolName,
            toolInput: $toolInput,
            toolOutput: $output,
            status: 'success',
            durationMs: self::elapsedMs($t0),
            traceId: $resolvedTrace,
            parentSpanId: $parentSpanId,
            accountId: $accountId,
            userId: $userId,
            result: $output,
            trackToolFn: $trackToolFn,
        );
    }

    /**
     * Emit a tool event without executing a function. Never throws: a failed emit sets `send_error`.
     *
     * @return array<string, mixed>
     */
    public static function recordToolEvent(
        string $toolName,
        mixed $toolInput,
        mixed $toolOutput,
        string $status,
        int $durationMs = 0,
        ?string $traceId = null,
        ?string $parentSpanId = null,
        ?string $accountId = null,
        ?string $userId = null,
        mixed $result = null,
        ?callable $trackToolFn = null,
    ): array {
        $trackToolFn ??= static fn (array $options) => Tokentify::trackTool($options);

        $payload = [
            'trace_id' => self::resolveTrace($traceId),
            'span_id' => self::newSpanId(),
            'parent_span_id' => $parentSpanId,
            'tool_name' => $toolName,
            'tool_input' => $toolInput,
            'tool_output' => $toolOutput,
            'status' => $status,
            'duration_ms' => max(0, $durationMs),
            'sent' => false,
        ];
        if ($accountId !== null && $accountId !== '') {
            $payload['account_id'] = $accountId;
        }
        if ($userId !== null && $userId !== '') {
            $payload['user_id'] = $userId;
        }
        if ($result !== null) {
            $payload['result'] = $result;
        }

        try {
            $emit = $payload;
            unset($emit['sent'], $emit['send_error'], $emit['result']);
            $trackToolFn($emit);
            $payload['sent'] = true;
        } catch (\Throwable $e) {
            $payload['send_error'] = $e->getMessage();
        }

        return $payload;
    }

    private static function resolveTrace(?string $traceId): string
    {
        $id = trim((string) ($traceId ?? self::currentTraceId() ?? ''));

        return $id !== '' ? $id : self::newTraceId();
    }

    private static function elapsedMs(int|float $t0): int
    {
        return (int) ((hrtime(true) - $t0) / 1_000_000);
    }
}
