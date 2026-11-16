<?php

declare(strict_types=1);

namespace Tokentify;

use Tokentify\Exception\TransportException;

/**
 * Batches usage events and POSTs them to the collector `/v1/ingest` endpoint.
 *
 * PHP has no background thread, so this is the synchronous equivalent of the Python emitter:
 * events sit in an in-memory queue and are sent when the queue reaches the batch size, when you call
 * {@see Tokentify::flush()}, or when the PHP process shuts down (`register_shutdown_function`).
 * Long-running workers (queues, Octane, RoadRunner, Swoole) should call `Tokentify::flush()` after each job.
 *
 * Retries 429 / 5xx / network errors with exponential backoff, bounded by the flush deadline.
 * Delivery failures are dropped (logged in debug mode) unless `failOnDeliveryError` is set.
 */
final class Emitter
{
    public const BATCH_SIZE = 50;

    public const MAX_QUEUE_SIZE = 10000;

    public const MAX_RETRIES = 5;

    public const RETRY_BACKOFF_BASE = 0.5;

    /** Seconds allowed for the final flush when PHP shuts down. */
    public const SHUTDOWN_FLUSH_TIMEOUT = 2.0;

    private readonly string $collectorUrl;

    private readonly int $batchSize;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    private bool $shutdownRegistered = false;

    private bool $closed = false;

    /** @var (callable(string, string, array<string, string>, ?string, float): array{0: ?int, 1: array<string, string>, 2: string})|null */
    private $httpHandler;

    /**
     * @param (callable(string, string, array<string, string>, ?string, float): array{0: ?int, 1: array<string, string>, 2: string})|null $httpHandler
     *        Optional replacement for the built-in cURL / stream client: `fn(method, url, headers, body, timeout)`
     *        returning `[status|null, headers, body]`. Used by tests and by apps that route egress through their own client.
     */
    public function __construct(
        string $collectorUrl,
        private readonly string $apiKey,
        private readonly bool $debug = false,
        private readonly float $timeout = 30.0,
        private readonly bool $verifyTls = true,
        private readonly bool $failOnDeliveryError = false,
        int $batchSize = self::BATCH_SIZE,
        private readonly bool $autoFlushOnBatch = true,
        ?callable $httpHandler = null,
    ) {
        $this->collectorUrl = rtrim($collectorUrl, '/');
        $this->batchSize = max(1, $batchSize);
        $this->httpHandler = $httpHandler;
    }

    public function collectorUrl(): string
    {
        return $this->collectorUrl;
    }

    /** Number of events waiting to be sent. */
    public function queueDepth(): int
    {
        return count($this->queue);
    }

    /**
     * Events waiting to be sent (copy).
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->queue;
    }

    // ── Health check ─────────────────────────────────────────────────

    /**
     * Verify the collector is reachable (`GET /health`).
     *
     * @throws TransportException
     */
    public function checkConnection(): void
    {
        $url = $this->collectorUrl . '/health';
        [$status, , $body] = $this->request('GET', $url, null, min($this->timeout, 10.0));
        if ($status === null) {
            throw TransportException::collectorUnreachable('GET /health', $url, new \RuntimeException($body));
        }
        if ($status < 200 || $status >= 300) {
            throw TransportException::badHttpStatus('GET /health', $url, $status, $body);
        }
        $this->log('collector health check OK');
    }

    /**
     * Verify the ingest API key is accepted (`POST /v1/ingest` with an empty batch).
     *
     * @throws TransportException
     */
    public function checkIngestAuth(): void
    {
        $url = $this->collectorUrl . '/v1/ingest';
        [$status, , $body] = $this->request('POST', $url, '{"events":[]}', min($this->timeout, 10.0));
        if ($status === null) {
            throw TransportException::collectorUnreachable('POST /v1/ingest (auth check)', $url, new \RuntimeException($body));
        }
        if ($status === 401 || $status === 403) {
            throw TransportException::invalidApiKey($url, $status, $body);
        }
        if ($status >= 400) {
            throw TransportException::badHttpStatus('POST /v1/ingest (auth check)', $url, $status, $body);
        }
        $this->log('ingest auth check OK');
    }

    // ── Public API ───────────────────────────────────────────────────

    /**
     * Add an event to the queue. Drops it (debug warning) when the queue is full.
     *
     * @param array<string, mixed> $event
     */
    public function enqueue(array $event): void
    {
        $this->registerShutdownFlush();
        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            $this->log('queue full, dropping event', true);

            return;
        }
        $this->queue[] = $event;
        if ($this->autoFlushOnBatch && count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Send every queued event, blocking up to $timeout seconds (retries stop at the deadline).
     *
     * @throws TransportException Only when `failOnDeliveryError` is enabled.
     */
    public function flush(float $timeout = 5.0): void
    {
        $deadline = microtime(true) + max(0.0, $timeout);
        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, $this->batchSize);
            $this->deliver($batch, $deadline);
        }
    }

    /**
     * Remove context-window Redis state for an ended session (`DELETE /v1/sessions/{id}`).
     */
    public function endSession(string $sessionId, float $timeout = 5.0): bool
    {
        $sid = trim($sessionId);
        if ($sid === '') {
            return false;
        }
        [$status, , $body] = $this->request('DELETE', $this->collectorUrl . '/v1/sessions/' . rawurlencode($sid), null, $timeout);
        if ($status === null) {
            $this->log("end_session failed for {$sid}: {$body}", true);

            return false;
        }
        if ($status >= 400) {
            $this->log("end_session returned {$status}: " . substr($body, 0, 200), true);

            return false;
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $status < 300;
        }

        return is_array($data) && (bool) ($data['cleared'] ?? false);
    }

    /** Flush what is queued and stop accepting the shutdown hook's work. */
    public function shutdown(float $timeout = 5.0): void
    {
        try {
            $this->flush($timeout);
        } finally {
            $this->closed = true;
        }
    }

    /**
     * Synchronously POST one batch and return `[status, headers, body]` without retrying.
     *
     * For callers that own their own durable queue: they decide whether to delete, retry or dead-letter
     * the batch (and can honour `Retry-After` on a 429). `status` is null when no response came back
     * (connection error / timeout); the body then carries the error message.
     *
     * @param list<array<string, mixed>> $batch
     * @return array{0: ?int, 1: array<string, string>, 2: string}
     */
    public function sendBatch(array $batch): array
    {
        if ($batch === []) {
            return [null, [], ''];
        }
        try {
            $payload = json_encode(['events' => $batch], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            return [null, [], 'tokentify: could not encode events: ' . $e->getMessage()];
        }

        return $this->request('POST', $this->collectorUrl . '/v1/ingest', $payload, $this->timeout);
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * POST a batch with retries on 429 / 5xx / network errors until $deadline. Drops on failure.
     *
     * @param list<array<string, mixed>> $batch
     * @throws TransportException
     */
    private function deliver(array $batch, float $deadline): void
    {
        $url = $this->collectorUrl . '/v1/ingest';
        $lastError = null;
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            [$status, , $body] = $this->sendBatch($batch);
            if ($status !== null && $status < 400) {
                $this->log('sent ' . count($batch) . ' events');

                return;
            }
            if ($status !== null && $status !== 429 && $status < 500) {
                $lastError = TransportException::badHttpStatus('POST /v1/ingest', $url, $status, $body);
                break; // not retryable
            }
            $lastError = $status === null
                ? TransportException::collectorUnreachable('POST /v1/ingest', $url, new \RuntimeException($body))
                : TransportException::badHttpStatus('POST /v1/ingest', $url, $status, $body);

            $sleep = self::RETRY_BACKOFF_BASE * (2 ** $attempt);
            if ($attempt === self::MAX_RETRIES || microtime(true) + $sleep >= $deadline) {
                break;
            }
            usleep((int) ($sleep * 1_000_000));
        }

        $this->log('dropping ' . count($batch) . ' events: ' . ($lastError?->getMessage() ?? 'unknown error'), true);
        if ($this->failOnDeliveryError && $lastError !== null) {
            throw $lastError;
        }
    }

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            if ($this->closed) {
                return;
            }
            try {
                $this->flush(self::SHUTDOWN_FLUSH_TIMEOUT);
            } catch (\Throwable) {
                // Never break shutdown.
            }
        });
    }

    /**
     * @return array{0: ?int, 1: array<string, string>, 2: string}
     */
    private function request(string $method, string $url, ?string $body, float $timeout): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'tokentify-sdk-php/' . LlmEvents::SDK_VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            if ($this->httpHandler !== null) {
                return ($this->httpHandler)($method, $url, $headers, $body, $timeout);
            }
            if (function_exists('curl_init')) {
                return $this->requestCurl($method, $url, $headers, $body, $timeout);
            }

            return $this->requestStream($method, $url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            return [null, [], $e->getMessage()];
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: ?int, 1: array<string, string>, 2: string}
     */
    private function requestCurl(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [null, [], 'curl_init failed for ' . $url];
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $timeoutMs = (int) max(1000, ceil($timeout * 1000));
        $options = [
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 5000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }
        if (! $this->verifyTls) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);
        if (curl_errno($ch) !== 0) {
            $error = curl_error($ch);
            curl_close($ch);

            return [null, [], 'curl error (' . $url . '): ' . $error];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $responseHeaders, is_string($responseBody) ? $responseBody : ''];
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: ?int, 1: array<string, string>, 2: string}
     */
    private function requestStream(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $headerText = '';
        foreach ($headers as $name => $value) {
            $headerText .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headerText,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'content' => $body ?? '',
            ],
            'ssl' => [
                'verify_peer' => $this->verifyTls,
                'verify_peer_name' => $this->verifyTls,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            return [null, [], 'request failed for ' . $url];
        }
        $status = null;
        $responseHeaders = [];
        /** @var list<string> $http_response_header */
        foreach ($http_response_header ?? [] as $i => $line) {
            if ($i === 0 && preg_match('#HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [$status, $responseHeaders, $responseBody];
    }

    private function log(string $message, bool $warning = false): void
    {
        if ($this->debug) {
            error_log('tokentify: ' . ($warning ? 'warning: ' : '') . $message);
        }
    }
}
