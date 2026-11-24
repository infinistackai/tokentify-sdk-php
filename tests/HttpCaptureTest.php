<?php

declare(strict_types=1);

namespace Tokentify\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tokentify\Http\MeteringStream;
use Tokentify\Http\Transport;
use Tokentify\Tokentify;

final class HttpCaptureTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::initForTests();
        $this->history = [];
    }

    /** Guzzle client with the Tokentify middleware over a mock handler. */
    private function client(Response|\Throwable ...$responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Tokentify::guzzleMiddleware(), 'tokentify');
        $stack->push(Middleware::history($this->history)); // innermost: sees what goes on the wire

        return new Client(['handler' => $stack]);
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    private static function sse(string ...$events): Response
    {
        $body = '';
        foreach ($events as $event) {
            $body .= "data: {$event}\n\n";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    public function testNonStreamingOpenAiCallIsMetered(): void
    {
        $body = ['id' => 'c1', 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4]];
        $response = $this->client(self::json($body))->post('https://api.openai.com/v1/chat/completions', [
            'json' => ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'hi']]],
        ]);

        $this->assertSame($body, json_decode((string) $response->getBody(), true), 'caller still gets the body');
        $event = self::lastEvent();
        $this->assertSame('openai', $event['provider']);
        $this->assertSame('gpt-4o-mini', $event['model']);
        $this->assertSame(11, $event['input_tokens']);
        $this->assertSame(4, $event['output_tokens']);
        $this->assertSame('/v1/chat/completions', $event['metadata']['endpoint']);
        $this->assertSame(200, $event['http_status']);
        $this->assertSame('success', $event['status']);
        $this->assertSame('test-bucket', $event['bucket_name']);
    }

    public function testTagsAreSnapshotAtRequestTime(): void
    {
        $client = $this->client(self::json(['usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]));
        Tokentify::withTags(['user_id' => 'u_42', 'session_id' => 's_1'], fn () => $client->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o']]));
        $meta = self::lastEvent()['metadata'];
        $this->assertSame('u_42', $meta['user_id']);
        $this->assertSame('s_1', $meta['session_id']);
    }

    public function testRequestBodyUserBecomesUserId(): void
    {
        $client = $this->client(self::json(['usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]));
        Tokentify::withTags(['user_id' => 'tag-user'], fn () => $client->post('https://api.openai.com/v1/chat/completions', [
            'json' => ['model' => 'gpt-4o', 'user' => 'end-user-7'],
        ]));
        $this->assertSame('end-user-7', self::lastEvent()['metadata']['user_id']);
    }

    public function testTokentifyHeadersAreStrippedOnlyForAiHosts(): void
    {
        $client = $this->client(
            self::json(['usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
            self::json(['ok' => true]),
        );
        $headers = ['X-Tokentify-User-Id' => 'u1', 'X-Tokentify-Api-Key' => 'um_secret', 'Authorization' => 'Bearer sk'];
        $client->post('https://api.openai.com/v1/chat/completions', ['headers' => $headers, 'json' => ['model' => 'gpt-4o']]);
        $client->post('http://proxy.tokentify.ai/v1/chat/completions', ['headers' => $headers, 'json' => ['model' => 'gpt-4o']]);

        $toProvider = $this->history[0]['request'];
        $this->assertFalse($toProvider->hasHeader('X-Tokentify-User-Id'));
        $this->assertFalse($toProvider->hasHeader('X-Tokentify-Api-Key'));
        $this->assertSame('Bearer sk', $toProvider->getHeaderLine('Authorization'));
        $toGateway = $this->history[1]['request'];
        $this->assertSame('um_secret', $toGateway->getHeaderLine('X-Tokentify-Api-Key'));
        $this->assertCount(1, self::events(), 'non-AI hosts are not metered');
    }

    public function testErrorResponseIsMeteredWithErrorStatus(): void
    {
        $client = $this->client(self::json(['error' => ['message' => 'x'], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 0]], 400));
        try {
            $client->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o']]);
        } catch (\GuzzleHttp\Exception\ClientException) {
        }
        $event = self::lastEvent();
        $this->assertSame('error', $event['status']);
        $this->assertSame(400, $event['http_status']);
    }

    public function testFailedCallIsRecordedLikeTheGateway(): void
    {
        $client = $this->client(self::json(['error' => [
            'message' => 'Incorrect API key: secret prompt', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key',
        ]], 401));
        $thrown = null;
        try {
            Tokentify::withTags(
                ['user_id' => 'u1', 'account_id' => 'acme', 'session_id' => 's1', 'team' => 'search'],
                fn () => $client->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o-mini']]),
            );
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the caller still gets the 401 as before');
        $this->assertSame(401, $thrown->getResponse()->getStatusCode());

        $this->assertCount(1, self::events());
        $event = self::lastEvent();
        $this->assertSame('error', $event['status']);
        $this->assertSame(401, $event['http_status']);
        $this->assertSame('invalid_api_key', $event['error_code']);
        $this->assertSame('/v1/chat/completions', $event['endpoint']);
        $this->assertSame('none', $event['usage_source']);
        $this->assertSame('gpt-4o-mini', $event['model']);
        $this->assertSame(0, $event['input_tokens']);
        $this->assertSame(0, $event['output_tokens']);
        $this->assertIsInt($event['latency_ms']);
        $this->assertSame(
            ['u1', 'acme', 's1', 'search'],
            [$event['metadata']['user_id'], $event['metadata']['account_id'], $event['metadata']['session_id'], $event['metadata']['team']],
        );
        $this->assertStringNotContainsString('Incorrect API key', json_encode($event), 'the error message is never sent');
    }

    /** @return iterable<string, array{0: int, 1: array<string, mixed>, 2: string}> */
    public static function providerErrorBodies(): iterable
    {
        yield 'OpenAI error.code' => [429, ['error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded']], 'rate_limit_exceeded'];
        yield 'Anthropic error.type' => [400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error']], 'invalid_request_error'];
        yield 'Gemini error.status' => [403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 'PERMISSION_DENIED'];
        yield 'no error body' => [502, ['detail' => 'bad gateway'], 'http_502'];
    }

    /**
     * @dataProvider providerErrorBodies
     * @param array<string, mixed> $body
     */
    public function testErrorCodeComesFromTheProviderBody(int $status, array $body, string $want): void
    {
        try {
            $this->client(self::json($body, $status))->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'm']]);
        } catch (\GuzzleHttp\Exception\BadResponseException) {
        }
        $this->assertCount(1, self::events());
        $this->assertSame($want, self::lastEvent()['error_code']);
        $this->assertSame($status, self::lastEvent()['http_status']);
    }

    public function testErrorCodeHelper(): void
    {
        $this->assertSame(64, strlen(Transport::errorCode(json_encode(['error' => ['code' => str_repeat('x', 100)]]), 400)));
        $this->assertSame('http_503', Transport::errorCode('<html>oops</html>', 503));
        $this->assertSame('error', Transport::errorCode(null));
    }

    public function testFailedNonLlmCallsAreNotRecorded(): void
    {
        try {
            $this->client(self::json(['error' => ['code' => 'nope']], 404))->get('https://api.openai.com/v1/models');
        } catch (\GuzzleHttp\Exception\ClientException) {
        }
        try {
            $this->client(self::json(['error' => ['code' => 'nope']], 404))->get('https://api.openai.com/v1/chat/completions/abc');
        } catch (\GuzzleHttp\Exception\ClientException) {
        }
        $this->assertSame([], self::events(), 'unknown endpoint and non-POST calls are not recorded');
    }

    public function testNetworkErrorIsRecordedAndRethrown(): void
    {
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions');
        $client = $this->client(new \GuzzleHttp\Exception\ConnectException('Connection refused', $request));
        $thrown = null;
        try {
            Tokentify::withTags(['account_id' => 'acme'], fn () => $client->post(
                'https://api.openai.com/v1/chat/completions',
                ['json' => ['model' => 'gpt-4o-mini']],
            ));
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'the caller still gets the original exception');
        $this->assertSame('Connection refused', $thrown->getMessage());

        $this->assertCount(1, self::events());
        $event = self::lastEvent();
        $this->assertSame('error', $event['status']);
        $this->assertSame('ConnectException', $event['error_code']);
        $this->assertArrayNotHasKey('http_status', $event, 'no response, so no http_status (as the gateway)');
        $this->assertSame('/v1/chat/completions', $event['endpoint']);
        $this->assertSame('none', $event['usage_source']);
        $this->assertSame('acme', $event['metadata']['account_id']);
    }

    public function testPsr18NetworkErrorIsRecordedAndRethrown(): void
    {
        $inner = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new \GuzzleHttp\Exception\ConnectException('timed out', $request);
            }
        };
        $factory = new HttpFactory();
        $request = $factory->createRequest('POST', 'https://api.anthropic.com/v1/messages')
            ->withBody($factory->createStream('{"model":"claude-haiku-4-5"}'));
        $this->expectException(\GuzzleHttp\Exception\ConnectException::class);
        try {
            Tokentify::httpClient($inner)->sendRequest($request);
        } finally {
            $event = self::lastEvent();
            $this->assertSame('error', $event['status']);
            $this->assertSame('ConnectException', $event['error_code']);
            $this->assertSame('/v1/messages', $event['endpoint']);
            $this->assertSame('claude-haiku-4-5', $event['model']);
        }
    }

    public function testSuccessReportsUsageSource(): void
    {
        $this->client(self::json(['usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1]]))
            ->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o']]);
        $this->assertSame('reported', self::lastEvent()['usage_source']);
    }

    public function testResponsesWithoutUsageEmitNothing(): void
    {
        $this->client(self::json(['data' => []]))->get('https://api.openai.com/v1/models');
        $this->assertSame([], self::events());
    }

    public function testToolCallsAddTags(): void
    {
        $body = [
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
            'choices' => [['message' => ['tool_calls' => [['type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{}']]]]]],
        ];
        $this->client(self::json($body))->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o']]);
        $meta = self::lastEvent()['metadata'];
        $this->assertSame(1, $meta['tool_call_count'] ?? null);
        $this->assertContains('get_weather', (array) ($meta['tool_names'] ?? []));
    }

    public function testAnthropicStreamingMergesUsageFragments(): void
    {
        $response = $this->client(self::sse(
            '{"type":"message_start","message":{"usage":{"input_tokens":25,"output_tokens":1,"cache_read_input_tokens":10}}}',
            '{"type":"content_block_delta","delta":{"text":"Hi"}}',
            '{"type":"message_delta","usage":{"output_tokens":15}}',
        ))->post('https://api.anthropic.com/v1/messages', ['json' => ['model' => 'claude-sonnet-4-5', 'stream' => true], 'stream' => true]);

        $this->assertSame([], self::events(), 'nothing is emitted before the stream is read');
        $body = $response->getBody();
        $this->assertInstanceOf(MeteringStream::class, $body);
        $text = '';
        while (! $body->eof()) {
            $text .= $body->read(7);
        }
        $this->assertStringContainsString('"text":"Hi"', $text, 'bytes pass through unchanged');

        $this->assertCount(1, self::events());
        $event = self::lastEvent();
        $this->assertSame('anthropic', $event['provider']);
        $this->assertSame('claude-sonnet-4-5', $event['model']);
        $this->assertSame(25, $event['input_tokens']);
        $this->assertSame(15, $event['output_tokens']);
        $this->assertSame(10, $event['cache_read_tokens']);
        $this->assertSame('/v1/messages', $event['metadata']['endpoint']);

        $body->close();
        $this->assertCount(1, self::events(), 'finalized exactly once');
    }

    public function testOpenAiStreamingWithUsageChunkAndDone(): void
    {
        $response = $this->client(self::sse(
            '{"choices":[{"delta":{"content":"a"}}]}',
            '{"choices":[],"usage":{"prompt_tokens":9,"completion_tokens":6}}',
            '[DONE]',
        ))->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o', 'stream' => true]]);

        $all = (string) $response->getBody();
        $this->assertStringEndsWith("data: [DONE]\n\n", $all);
        $event = self::lastEvent();
        $this->assertSame(9, $event['input_tokens']);
        $this->assertSame(6, $event['output_tokens']);

        $response->getBody()->rewind();
        $response->getBody()->getContents();
        $this->assertCount(1, self::events(), 're-reading does not double count');
    }

    public function testStreamWithoutTrailingNewlineIsStillParsed(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/event-stream; charset=utf-8'], 'data: {"usage":{"prompt_tokens":2,"completion_tokens":3}}');
        $this->client($response)->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o']])->getBody()->getContents();
        $this->assertSame(3, self::lastEvent()['output_tokens']);
    }

    public function testPartiallyReadStreamFinalizesWhenReleased(): void
    {
        $response = $this->client(self::sse('{"usage":{"prompt_tokens":4,"completion_tokens":4}}'))
            ->post('https://api.openai.com/v1/chat/completions', ['json' => ['model' => 'gpt-4o'], 'stream' => true]);
        $line = 'data: {"usage":{"prompt_tokens":4,"completion_tokens":4}}' . "\n";
        $this->assertSame($line, $response->getBody()->read(strlen($line))); // caller stops before EOF
        $this->assertSame([], self::events(), 'not emitted until the stream ends');
        unset($response);
        $this->history = [];
        gc_collect_cycles();
        $this->assertSame(4, self::lastEvent()['input_tokens']);
    }

    public function testGeminiModelComesFromThePath(): void
    {
        $body = ['usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 8, 'totalTokenCount' => 20]];
        $this->client(self::json($body))->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', ['json' => ['contents' => []]]);
        $event = self::lastEvent();
        $this->assertSame('google', $event['provider']);
        $this->assertSame('gemini-2.0-flash', $event['model']);
        $this->assertSame(12, $event['input_tokens']);
        $this->assertSame('generateContent', $event['metadata']['endpoint']);
    }

    public function testTextToSpeechIsMeteredInCharacters(): void
    {
        $audio = new Response(200, ['Content-Type' => 'audio/mpeg'], "\xff\xfb binary");
        $response = $this->client($audio)->post('https://api.openai.com/v1/audio/speech', [
            'json' => ['model' => 'tts-1', 'input' => 'Hello world', 'voice' => 'alloy'],
        ]);
        $this->assertSame("\xff\xfb binary", (string) $response->getBody());
        $event = self::lastEvent();
        $this->assertSame('tts-1', $event['model']);
        $this->assertSame('characters', $event['metadata']['billable_unit']);
        $this->assertEquals(11, $event['metadata']['billable_quantity']);
    }

    public function testFailedSpeechCallEmitsZeroQuantity(): void
    {
        try {
            $this->client(self::json(['error' => ['message' => 'bad']], 500))->post('https://api.openai.com/v1/audio/transcriptions', ['body' => 'x']);
        } catch (\GuzzleHttp\Exception\ServerException) {
        }
        $event = self::lastEvent();
        $this->assertSame('error', $event['status']);
        $this->assertSame('seconds', $event['metadata']['billable_unit']);
        $this->assertEquals(0, $event['metadata']['billable_quantity']);
    }

    public function testProviderBucketsApplyToCapturedCalls(): void
    {
        Tokentify::reset();
        Tokentify::setHttpHandler(fn () => [200, [], '{}']);
        self::initForTests(bucket: null, requireBucket: false, providerBuckets: ['anthropic' => 'claude-costs']);
        $this->client(self::json(['usage' => ['input_tokens' => 1, 'output_tokens' => 1]]))
            ->post('https://api.anthropic.com/v1/messages', ['json' => ['model' => 'claude-x']]);
        $this->assertSame('claude-costs', self::lastEvent()['bucket_name']);
    }

    public function testNotMeteredBeforeSetupButHeadersStillStripped(): void
    {
        Tokentify::reset();
        $this->client(self::json(['usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]))
            ->post('https://api.openai.com/v1/chat/completions', ['headers' => ['X-Tokentify-Feature' => 'x'], 'json' => ['model' => 'gpt-4o']]);
        $this->assertNull(Tokentify::emitter());
        $this->assertFalse($this->history[0]['request']->hasHeader('X-Tokentify-Feature'));
    }

    public function testPsr18ClientDecorator(): void
    {
        $inner = new class () implements ClientInterface {
            public ?RequestInterface $seen = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen = $request;
                $body = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
                $body->write('{"usage":{"input_tokens":30,"output_tokens":3}}');
                $body->seek(0);

                return new Response(200, ['Content-Type' => 'application/json'], $body);
            }
        };
        $client = Tokentify::httpClient($inner);
        $factory = new HttpFactory();
        $request = $factory->createRequest('POST', 'https://api.anthropic.com/v1/messages')
            ->withHeader('X-Tokentify-Session-Id', 's')
            ->withBody($factory->createStream('{"model":"claude-haiku-4-5"}'));

        $response = $client->sendRequest($request);
        $this->assertSame('{"usage":{"input_tokens":30,"output_tokens":3}}', $response->getBody()->getContents());
        $this->assertFalse($inner->seen?->hasHeader('X-Tokentify-Session-Id'));
        $this->assertSame(30, self::lastEvent()['input_tokens']);
        $this->assertSame('claude-haiku-4-5', self::lastEvent()['model']);
    }

    public function testTransportNeverThrows(): void
    {
        $transport = new Transport(
            static function (): void {
                throw new \RuntimeException('emit failed');
            },
            static fn (): array => [],
            static fn (string $p, array $t): array => $t,
            static fn (): bool => true,
        );
        [$request, $ctx] = $transport->beforeRequest(new Request('POST', 'https://api.openai.com/v1/chat/completions', [], '{"model":"m"}'));
        $this->assertNotNull($ctx);
        $response = $transport->afterResponse($ctx, self::json(['usage' => ['prompt_tokens' => 1]]));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDetectionHelpers(): void
    {
        $this->assertSame('azure', Transport::detectProvider('my-res.openai.azure.com'));
        $this->assertSame('bedrock', Transport::detectProvider('bedrock-runtime.us-east-1.amazonaws.com'));
        $this->assertSame('openrouter', Transport::detectProvider('openrouter.ai'));
        $this->assertFalse(Transport::isAiRequest('example.com'));
        $this->assertSame('responses', Transport::detectEndpoint('/v1/responses/'));
        $this->assertSame('embed', Transport::detectEndpoint('/v1/embeddings'));
        $this->assertSame('audio/transcriptions', Transport::detectEndpoint('/v1/audio/transcriptions'));
        $this->assertSame('anthropic.claude-3-haiku-20240307-v1:0', Transport::modelFromPath('/model/anthropic.claude-3-haiku-20240307-v1%3A0/converse'));
        $this->assertSame('unknown', Transport::modelFromPath('/v1/chat/completions'));
    }
}
