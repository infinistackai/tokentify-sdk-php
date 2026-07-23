<?php

declare(strict_types=1);

namespace UsageMeter;

use UsageMeter\Exception\TransportException;

/**
 * Batches usage events and POSTs them to the collector /v1/ingest endpoint.
 *
 * Same batching semantics as the Python SDK: batch size 50, flush on shutdown
 * and when the queue exceeds the batch size.
 */
final class Emitter
{
    private const DEFAULT_BATCH_SIZE = 50;

    private const MAX_RETRIES = 5;

    private const BASE_BACKOFF_MS = 500;

    private readonly int $batchSize;

    private readonly bool $autoFlushOnBatch;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    private bool $shutdownHandlerRegistered = false;

    public function __construct(
        private readonly string $collectorUrl,
        private readonly string $apiKey,
        private readonly bool $debug,
        private readonly float $timeoutSeconds,
        private readonly bool $verifyTls,
        private readonly bool $failOnDeliveryError = false,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        bool $autoFlushOnBatch = true,
    ) {
        $this->batchSize = max(1, $batchSize);
        $this->autoFlushOnBatch = $autoFlushOnBatch;
    }

    public function queueDepth(): int
    {
        return count($this->queue);
    }

    public function enqueue(array $event): void
    {
        $this->ensureShutdownFlush();
        $this->queue[] = $event;
        if ($this->autoFlushOnBatch && count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Send all queued events (in chunks of BATCH_SIZE).
     *
     * @throws TransportException When fail_on_delivery_error is true and delivery fails after retries.
     */
    public function flush(): void
    {
        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, $this->batchSize);
            $this->sendBatch($batch);
        }
    }

    /**
     * @throws TransportException
     */
    public function checkConnection(): void
    {
        $url = rtrim($this->collectorUrl, '/') . '/health';
        try {
            $result = $this->request('GET', $url, null);
        } catch (\Throwable $e) {
            throw TransportException::collectorUnreachable('GET /health', $url, $e);
        }
        if ($result['code'] < 200 || $result['code'] >= 300) {
            throw TransportException::badHttpStatus('GET /health', $url, $result['code'], $result['body']);
        }
    }

    /**
     * Validates Bearer token against POST /v1/ingest with an empty events list.
     *
     * @throws TransportException
     */
    public function checkIngestAuth(): void
    {
        $url = rtrim($this->collectorUrl, '/') . '/v1/ingest';
        try {
            $result = $this->request('POST', $url, '{"events":[]}');
        } catch (\Throwable $e) {
            throw TransportException::collectorUnreachable('POST /v1/ingest (auth probe)', $url, $e);
        }
        $code = $result['code'];
        if ($code === 401 || $code === 403) {
            throw TransportException::badHttpStatus(
                'POST /v1/ingest (auth probe)',
                $url,
                $code,
                $result['body'],
            );
        }
        if ($code < 200 || $code >= 300) {
            throw TransportException::badHttpStatus(
                'POST /v1/ingest (auth probe)',
                $url,
                $code,
                $result['body'],
            );
        }
    }

    /**
     * Remove context-window Redis state for an ended session.
     */
    public function endSession(string $sessionId, float $timeout = 5.0): bool
    {
        $sid = trim($sessionId);
        if ($sid === '') {
            return false;
        }
        $url = rtrim($this->collectorUrl, '/') . '/v1/sessions/' . rawurlencode($sid);
        try {
            $result = $this->request('DELETE', $url, null, $timeout);
        } catch (\Throwable $e) {
            if ($this->debug) {
                error_log('[usagemeter-php] endSession failed for ' . $sid . ': ' . $e->getMessage());
            }

            return false;
        }
        if ($result['code'] >= 400) {
            if ($this->debug) {
                error_log(
                    '[usagemeter-php] endSession returned ' . $result['code'] . ': '
                    . substr($result['body'], 0, 200)
                );
            }

            return false;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($data)) {
                return (bool) ($data['cleared'] ?? false);
            }
        } catch (\JsonException) {
            return $result['code'] >= 200 && $result['code'] < 300;
        }

        return $result['code'] >= 200 && $result['code'] < 300;
    }

    private function ensureShutdownFlush(): void
    {
        if ($this->shutdownHandlerRegistered) {
            return;
        }
        $this->shutdownHandlerRegistered = true;
        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (\Throwable) {
                // Never break shutdown
            }
        });
    }

    /**
     * @param list<array<string, mixed>> $batch
     *
     * @throws TransportException
     */
    private function sendBatch(array $batch): void
    {
        if ($batch === []) {
            return;
        }
        $url = rtrim($this->collectorUrl, '/') . '/v1/ingest';
        $body = json_encode(['events' => $batch], JSON_THROW_ON_ERROR);

        $lastError = null;
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $result = $this->request('POST', $url, $body);
                $code = $result['code'];
                if ($code < 400) {
                    if ($this->debug) {
                        error_log('[usagemeter-php] Sent ' . count($batch) . ' events');
                    }

                    return;
                }
                if ($code >= 400 && $code < 500 && $code !== 429) {
                    if ($this->debug) {
                        error_log('[usagemeter-php] Non-retryable HTTP ' . $code . ' body=' . substr($result['body'], 0, 300));
                    }
                    if ($this->failOnDeliveryError) {
                        throw TransportException::badHttpStatus('POST /v1/ingest', $url, $code, $result['body']);
                    }

                    return;
                }
                $lastError = TransportException::badHttpStatus('POST /v1/ingest', $url, $code, $result['body']);
            } catch (TransportException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $lastError = TransportException::collectorUnreachable('POST /v1/ingest', $url, $e);
            }
            if ($attempt < self::MAX_RETRIES - 1) {
                usleep((int) (self::BASE_BACKOFF_MS * 1000 * (2 ** $attempt)));
            }
        }
        if ($this->failOnDeliveryError && $lastError !== null) {
            throw $lastError;
        }
        if ($this->debug && $lastError !== null) {
            error_log('[usagemeter-php] Dropped batch after retries: ' . $lastError->getMessage());
        }
    }

    /**
     * @return array{code: int, body: string}
     */
    private function request(string $method, string $url, ?string $body, ?float $timeoutSeconds = null): array
    {
        if (function_exists('curl_init')) {
            return $this->requestCurl($method, $url, $body, $timeoutSeconds);
        }

        return $this->requestStream($method, $url, $body, $timeoutSeconds);
    }

    /**
     * @return array{code: int, body: string}
     */
    private function requestCurl(string $method, string $url, ?string $body, ?float $timeoutSeconds = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed for ' . $url);
        }
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: tokentifyai-usagemeter-php/' . LlmEvents::SDK_VERSION,
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
        }
        $timeout = $timeoutSeconds ?? $this->timeoutSeconds;
        $curlOpts = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => (int) max(1, (int) ceil($timeout)),
        ];
        if ($method === 'GET') {
            $curlOpts[CURLOPT_HTTPGET] = true;
        } else {
            $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $curlOpts[CURLOPT_POSTFIELDS] = $body;
            }
        }
        curl_setopt_array($ch, $curlOpts);
        if (! $this->verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $responseBody = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch) !== 0) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('curl error (' . $url . '): ' . $err);
        }
        curl_close($ch);

        return [
            'code' => $code,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    /**
     * @return array{code: int, body: string}
     */
    private function requestStream(string $method, string $url, ?string $body, ?float $timeoutSeconds = null): array
    {
        $headers = 'Authorization: Bearer ' . $this->apiKey . "\r\n"
            . 'User-Agent: tokentifyai-usagemeter-php/' . LlmEvents::SDK_VERSION . "\r\n";
        if ($method === 'POST' && $body !== null) {
            $headers .= "Content-Type: application/json\r\n";
        }
        $timeout = $timeoutSeconds ?? $this->timeoutSeconds;
        $ctx = [
            'http' => [
                'method' => $method,
                'header' => $headers,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->verifyTls,
                'verify_peer_name' => $this->verifyTls,
            ],
        ];
        if ($method === 'POST' && $body !== null) {
            $ctx['http']['content'] = $body;
        }
        $context = stream_context_create($ctx);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new \RuntimeException('file_get_contents failed for ' . $url);
        }
        $code = 0;
        if (isset($http_response_header[0]) && is_string($http_response_header[0])) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }

        return ['code' => $code, 'body' => is_string($responseBody) ? $responseBody : ''];
    }
}
