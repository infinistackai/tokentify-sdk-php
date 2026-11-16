<?php

declare(strict_types=1);

namespace Tokentify\Test;

use Tokentify\Emitter;
use Tokentify\Exception\TransportException;
use Tokentify\Tokentify;

final class EmitterTest extends TestCase
{
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->errorLog);
        parent::tearDown();
    }

    /** @param list<array{0: ?int, 1: array<string, string>, 2: string}> $responses */
    private function emitter(array $responses = [], bool $failOnDeliveryError = false, int $batchSize = 50, bool $autoFlush = false): Emitter
    {
        $this->responses = $responses;

        return new Emitter(
            'http://collector.test/',
            'um_k',
            failOnDeliveryError: $failOnDeliveryError,
            batchSize: $batchSize,
            autoFlushOnBatch: $autoFlush,
            httpHandler: function (string $method, string $url, array $headers, ?string $body): array {
                $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

                return array_shift($this->responses) ?? [200, [], '{}'];
            },
        );
    }

    public function testFlushPostsBatchWithAuthHeaders(): void
    {
        $emitter = $this->emitter();
        $emitter->enqueue(['event_id' => 'e1']);
        $emitter->enqueue(['event_id' => 'e2']);
        $this->assertSame(2, $emitter->queueDepth());
        $emitter->flush();

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('http://collector.test/v1/ingest', $request['url']);
        $this->assertSame('Bearer um_k', $request['headers']['Authorization']);
        $this->assertSame('tokentify-sdk-php/0.5.0', $request['headers']['User-Agent']);
        $this->assertSame(['events' => [['event_id' => 'e1'], ['event_id' => 'e2']]], json_decode((string) $request['body'], true));
        $this->assertSame(0, $emitter->queueDepth());
    }

    public function testRetriesOn503ThenSucceeds(): void
    {
        $emitter = $this->emitter([[503, [], 'busy'], [429, [], 'slow down'], [202, [], '{}']]);
        $emitter->enqueue(['event_id' => 'e1']);
        $emitter->flush(10.0);
        $this->assertCount(3, $this->requests);
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $emitter = $this->emitter([[422, [], 'invalid']]);
        $emitter->enqueue(['event_id' => 'e1']);
        $emitter->flush();
        $this->assertCount(1, $this->requests);
        $this->assertSame(0, $emitter->queueDepth(), 'dropped');
    }

    public function testFailOnDeliveryErrorThrows(): void
    {
        $emitter = $this->emitter([[400, [], 'bad']], failOnDeliveryError: true);
        $emitter->enqueue(['event_id' => 'e1']);
        $this->expectException(TransportException::class);
        $emitter->flush();
    }

    public function testRetriesStopAtTheFlushDeadline(): void
    {
        $emitter = $this->emitter([[null, [], 'refused'], [null, [], 'refused']]);
        $emitter->enqueue(['event_id' => 'e1']);
        $t0 = microtime(true);
        $emitter->flush(0.1);
        $this->assertLessThan(1.0, microtime(true) - $t0);
        $this->assertCount(1, $this->requests);
    }

    public function testAutoFlushAtBatchSize(): void
    {
        $emitter = $this->emitter(batchSize: 2, autoFlush: true);
        $emitter->enqueue(['event_id' => 'e1']);
        $this->assertCount(0, $this->requests);
        $emitter->enqueue(['event_id' => 'e2']);
        $this->assertCount(1, $this->requests);
    }

    public function testFlushSplitsIntoBatches(): void
    {
        $emitter = $this->emitter(batchSize: 2);
        foreach (range(1, 5) as $i) {
            $emitter->enqueue(['event_id' => "e{$i}"]);
        }
        $emitter->flush();
        $this->assertCount(3, $this->requests);
    }

    public function testSendBatchReturnsRawResult(): void
    {
        $emitter = $this->emitter([[429, ['retry-after' => '3'], 'slow']]);
        [$status, $headers, $body] = $emitter->sendBatch([['event_id' => 'e1']]);
        $this->assertSame(429, $status);
        $this->assertSame('3', $headers['retry-after']);
        $this->assertSame('slow', $body);
        $this->assertSame([null, [], ''], $emitter->sendBatch([]));
    }

    public function testCheckConnection(): void
    {
        $this->emitter()->checkConnection();
        $this->assertSame('http://collector.test/health', $this->requests[0]['url']);

        $this->expectException(TransportException::class);
        $this->emitter([[500, [], 'down']])->checkConnection();
    }

    public function testFacadeFlushDeliversQueuedEvents(): void
    {
        self::initForTests();
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);
        Tokentify::flush();
        $this->assertCount(1, $this->requests);
        $this->assertSame([], self::events());
    }
}
