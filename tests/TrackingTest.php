<?php

declare(strict_types=1);

namespace Tokentify\Test;

use GuzzleHttp\Psr7\Response;
use Tokentify\Exception\ValidationException;
use Tokentify\Tokentify;

final class TrackingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::initForTests(appName: 'unit-app');
    }

    public function testTrackLlmEmitsWireShape(): void
    {
        $usage = Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 10, output_tokens: 5, endpoint: 'chat');
        $this->assertSame(15, $usage->totalTokens());

        $event = self::lastEvent();
        $this->assertSame('openai', $event['provider']);
        $this->assertSame('gpt-4o', $event['model']);
        $this->assertSame(10, $event['input_tokens']);
        $this->assertSame(5, $event['output_tokens']);
        $this->assertSame('0.5.0', $event['sdk_version']);
        $this->assertSame('test-bucket', $event['bucket_name']);
        $this->assertSame('unit-app', $event['app_name']);
        $this->assertSame('production', $event['metadata']['environment']);
        $this->assertSame(15, $event['metadata']['total_tokens']);
        $this->assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/', $event['metadata']['request_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $event['event_id']);
    }

    public function testTrackLlmAcceptsAnArrayAndAliasKeys(): void
    {
        Tokentify::trackLlm(['provider' => 'openai', 'model' => 'gpt-4o', 'prompt_tokens' => 7, 'completion_tokens' => 3]);
        $event = self::lastEvent();
        $this->assertSame(7, $event['input_tokens']);
        $this->assertSame(3, $event['output_tokens']);
    }

    public function testTrackLlmRequiresProviderAndModel(): void
    {
        try {
            Tokentify::trackLlm(model: 'gpt-4o');
            $this->fail('expected provider error');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('requires provider', $e->getMessage());
        }
        $this->expectExceptionMessage('requires model');
        Tokentify::trackLlm(provider: 'openai');
    }

    public function testPerCallAttributionOverridesTags(): void
    {
        $scope = Tokentify::tag(user_id: 'tag-user', account_id: 'acct_1');
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1, user_id: 'call-user', session_id: 's1');
        $scope->restore();
        $meta = self::lastEvent()['metadata'];
        $this->assertSame('call-user', $meta['user_id']);
        $this->assertSame('s1', $meta['session_id']);
        $this->assertSame('acct_1', $meta['account_id']);
    }

    public function testTrackFromResponseOpenAiCached(): void
    {
        $usage = Tokentify::trackFromResponse(['provider' => 'openai', 'model' => 'gpt-4o'], self::fixture('openai_chat_cached.json'));
        $this->assertSame(400, $usage->inputTokens, 'normalized usage excludes cached prompt tokens');
        $this->assertSame(800, $usage->cacheReadTokens);
        $event = self::lastEvent();
        $this->assertSame(1200, $event['input_tokens'], 'OpenAI-family wire input is the full prompt (as the Python SDK sends)');
        $this->assertSame(420, $event['output_tokens']);
        $this->assertSame(800, $event['cache_read_tokens']);
    }

    public function testTrackFromResponseAnthropic(): void
    {
        $usage = Tokentify::trackFromResponse(['provider' => 'anthropic', 'model' => 'claude-haiku-4.5'], self::fixture('anthropic_messages_usage.json'));
        $this->assertSame(1000, $usage->inputTokens);
        $event = self::lastEvent();
        $this->assertSame(1000, $event['input_tokens']);
        $this->assertSame(500, $event['cache_read_tokens']);
        $this->assertSame(80, $event['cache_write_tokens']);
    }

    public function testTrackFromResponseAcceptsArraysObjectsAndPsr7(): void
    {
        $data = ['usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2]];
        Tokentify::trackFromResponse(['provider' => 'openai', 'model' => 'gpt-4o'], $data);
        $this->assertSame(4, self::lastEvent()['input_tokens']);

        $object = new class () {
            public function toArray(): array
            {
                return ['usage' => ['prompt_tokens' => 9, 'completion_tokens' => 1]];
            }
        };
        Tokentify::trackFromResponse(['provider' => 'openai', 'model' => 'gpt-4o'], $object);
        $this->assertSame(9, self::lastEvent()['input_tokens']);

        $response = new Response(429, ['Content-Type' => 'application/json'], json_encode($data));
        Tokentify::trackFromResponse(['provider' => 'openai', 'model' => 'gpt-4o'], $response);
        $event = self::lastEvent();
        $this->assertSame('error', $event['status']);
        $this->assertSame(429, $event['http_status']);
        $this->assertSame(json_encode($data), (string) $response->getBody(), 'body still readable');
    }

    public function testContextLimitIsAttachedFromResponse(): void
    {
        Tokentify::trackFromResponse(
            ['provider' => 'openai', 'model' => 'gpt-4o'],
            ['usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1], 'max_tokens' => 4096],
        );
        $this->assertSame(4096, self::lastEvent()['metadata']['max_tokens']);
    }

    public function testCustomTrackEvent(): void
    {
        Tokentify::track('sms', 'twilio', 'send', 2, 'segments', 0.0, ['account_id' => 'acct_1']);
        $event = self::lastEvent();
        $this->assertSame('sms', $event['event_type']);
        $this->assertSame('twilio', $event['provider']);
        $this->assertSame('send', $event['model']);
        $this->assertSame(2, $event['metadata']['segments']);
        $this->assertSame('segments', $event['metadata']['billable_unit']);
        $this->assertSame('acct_1', $event['metadata']['account_id']);
        $this->assertSame('test-bucket', $event['bucket_name']);
    }

    public function testTrackSpeech(): void
    {
        $usage = Tokentify::trackSpeech(provider: 'openai', model: 'tts-1', kind: 'tts', billable_unit: 'characters', billable_quantity: 120);
        $this->assertSame(120.0, $usage->billableQuantity);
        $event = self::lastEvent();
        $this->assertSame('tts-1', $event['model']);
        $this->assertSame('characters', $event['metadata']['billable_unit']);
    }

    public function testTrackFromSpeechResponseUsesDuration(): void
    {
        $usage = Tokentify::trackFromSpeechResponse(
            ['provider' => 'openai', 'model' => 'whisper-1', 'endpoint' => 'audio/transcriptions'],
            '{"text":"hi","usage":{"type":"duration","seconds":12.5}}',
        );
        $this->assertSame('stt', $usage->kind);
        $this->assertSame(12.5, $usage->billableQuantity);
        $this->assertSame('seconds', self::lastEvent()['metadata']['billable_unit']);
    }

    public function testTrackTool(): void
    {
        Tokentify::trackTool(tool_name: 'search', tool_input: ['q' => 'x'], tool_output: 'ok', status: 'success', trace_id: 'tr_1', span_id: 'sp_1', duration_ms: 7);
        $event = self::lastEvent();
        $this->assertSame('tool', $event['event_type']);
        $this->assertSame('search', $event['metadata']['tool_name']);
        $this->assertSame(7, $event['latency_ms']);
    }

    public function testTrackToolValidatesRequiredFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tokentify::trackTool(tool_name: 'search');
    }

    public function testLegacyTrackingFieldsAddGroupKeys(): void
    {
        Tokentify::reset();
        Tokentify::setHttpHandler(fn () => [200, [], '{}']);
        self::initForTests(trackingFields: ['account_id', 'user_id']);
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1, metadata: ['account_id' => 'a1', 'user_id' => 'u1']);
        $meta = self::lastEvent()['metadata'];
        $this->assertSame('account_id', $meta['group_key_1']);
        $this->assertSame('a1', $meta['group_value_1']);
        $this->assertSame('u1', $meta['group_value_2']);
    }

    public function testStrictTrackingValidationThrows(): void
    {
        Tokentify::reset();
        Tokentify::setHttpHandler(fn () => [200, [], '{}']);
        self::initForTests(trackingFields: ['account_id'], strictTrackingValidation: true);
        $this->expectException(ValidationException::class);
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);
    }
}
