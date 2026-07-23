<?php

declare(strict_types=1);

namespace UsageMeter\Test;

use UsageMeter\Meter;

final class TrackLlmTest extends TestCase
{
    private static function loadFixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            self::fail('Missing fixture: ' . $name);
        }

        return $contents;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::initMeterForTests();
    }

    public function testTrackFromResponseEmitsOpenAiCacheFields(): void
    {
        $body = self::loadFixture('openai_chat_cached.json');
        $usage = Meter::trackFromResponse(
            [
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'http_status' => 200,
            ],
            $body,
        );

        $events = self::queuedEvents();
        $this->assertSame(400, $usage->inputTokens);
        $this->assertSame(420, $usage->outputTokens);
        $this->assertSame(800, $usage->cacheReadTokens);
        $this->assertCount(1, $events);
        $this->assertSame(400, $events[0]['input_tokens']);
        $this->assertSame(420, $events[0]['output_tokens']);
        $this->assertSame(800, $events[0]['cache_read_tokens']);
    }

    public function testTrackFromResponseEmitsAnthropicCacheFields(): void
    {
        $body = self::loadFixture('anthropic_messages_usage.json');
        $usage = Meter::trackFromResponse(
            [
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4.5',
                'http_status' => 200,
            ],
            $body,
        );

        $events = self::queuedEvents();
        $this->assertSame(1000, $usage->inputTokens);
        $this->assertSame(200, $usage->outputTokens);
        $this->assertSame(500, $usage->cacheReadTokens);
        $this->assertSame(80, $usage->cacheWriteTokens);
        $this->assertSame(500, $events[0]['cache_read_tokens']);
        $this->assertSame(80, $events[0]['cache_write_tokens']);
    }

    public function testTrackWithResponseBodyEmitsOpenAiCacheFields(): void
    {
        self::initMeterForTests();
        $body = self::loadFixture('openai_chat_cached.json');
        Meter::track([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'response_body' => $body,
            'http_status' => 200,
        ]);

        $events = self::queuedEvents();
        $this->assertCount(1, $events);
        $this->assertSame(400, $events[0]['input_tokens']);
        $this->assertSame(420, $events[0]['output_tokens']);
        $this->assertSame(800, $events[0]['cache_read_tokens']);
    }

    public function testTrackResponseBodyKeepsExplicitTokenOverrides(): void
    {
        self::initMeterForTests();
        $body = self::loadFixture('openai_chat_cached.json');
        Meter::track([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'response_body' => $body,
            'input_tokens' => 1,
            'output_tokens' => 2,
        ]);

        $events = self::queuedEvents();
        $this->assertSame(1, $events[0]['input_tokens']);
        $this->assertSame(2, $events[0]['output_tokens']);
        $this->assertSame(800, $events[0]['cache_read_tokens']);
    }
}
