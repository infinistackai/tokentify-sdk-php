<?php

declare(strict_types=1);

namespace UsageMeter\Test;

use UsageMeter\Exception\ConfigurationException;
use UsageMeter\Meter;
use UsageMeter\ToolEvents;

final class TrackToolTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function getTags(): array
    {
        return [
            'environment' => 'test',
            'bucket_name' => 'agents',
            'tool_name' => 'from_tag',
        ];
    }

    public function testBuildToolIngestEventCanonicalKeys(): void
    {
        $event = ToolEvents::buildToolIngestEvent(
            [
                'trace_id' => 'tr_abc',
                'span_id' => 'sp_1',
                'parent_span_id' => 'sp_root',
                'tool_name' => 'get_weather',
                'tool_input' => ['city' => 'Paris'],
                'tool_output' => ['temp_c' => 22],
                'status' => 'success',
                'duration_ms' => 42,
                'account_id' => 'acct_1',
                'user_id' => 'user_9',
            ],
            static fn (): array => self::getTags(),
        );
        $this->assertSame(ToolEvents::TOOL_EVENT_TYPE, $event['event_type']);
        $this->assertSame('agent', $event['provider']);
        $this->assertSame('tool', $event['model']);
        $this->assertSame('tool', $event['endpoint']);
        $this->assertSame(0, $event['input_tokens']);
        $this->assertSame(0, $event['output_tokens']);
        $this->assertSame(42, $event['latency_ms']);
        $this->assertSame('success', $event['status']);
        $this->assertSame('agents', $event['bucket_name']);

        $meta = $event['metadata'];
        foreach (ToolEvents::TOOL_METADATA_KEYS as $key) {
            $this->assertArrayHasKey($key, $meta);
        }
        $this->assertSame('tool', $meta['event_type']);
        $this->assertSame('tr_abc', $meta['trace_id']);
        $this->assertSame('sp_1', $meta['span_id']);
        $this->assertSame('sp_root', $meta['parent_span_id']);
        $this->assertSame('get_weather', $meta['tool_name']);
        $this->assertSame(['city' => 'Paris'], $meta['tool_input']);
        $this->assertSame(['temp_c' => 22], $meta['tool_output']);
        $this->assertSame(42, $meta['duration_ms']);
        $this->assertSame('acct_1', $meta['account_id']);
        $this->assertSame('user_9', $meta['user_id']);
        $this->assertSame('test', $meta['environment']);
    }

    public function testBuildToolIngestEventNullParent(): void
    {
        $event = ToolEvents::buildToolIngestEvent(
            [
                'trace_id' => 'tr_1',
                'span_id' => 'sp_1',
                'tool_name' => 'lookup',
                'status' => 'error',
            ],
            static fn (): array => [],
        );
        $this->assertNull($event['metadata']['parent_span_id']);
        $this->assertArrayNotHasKey('latency_ms', $event);
    }

    /**
     * @return list<string>
     */
    public static function requiredFieldProvider(): array
    {
        return [
            ['trace_id'],
            ['span_id'],
            ['tool_name'],
            ['status'],
        ];
    }

    /**
     * @dataProvider requiredFieldProvider
     */
    public function testBuildToolIngestEventRequired(string $missing): void
    {
        $opts = [
            'trace_id' => 'tr',
            'span_id' => 'sp',
            'tool_name' => 't',
            'status' => 'success',
        ];
        unset($opts[$missing]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('track_tool');
        ToolEvents::buildToolIngestEvent($opts, static fn (): array => []);
    }

    public function testBuildToolIngestEventInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("status must be 'success' or 'error'");
        ToolEvents::buildToolIngestEvent(
            [
                'trace_id' => 'tr',
                'span_id' => 'sp',
                'tool_name' => 't',
                'status' => 'ok',
            ],
            static fn (): array => [],
        );
    }

    public function testBuildToolIngestEventNegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duration_ms');
        ToolEvents::buildToolIngestEvent(
            [
                'trace_id' => 'tr',
                'span_id' => 'sp',
                'tool_name' => 't',
                'status' => 'success',
                'duration_ms' => -1,
            ],
            static fn (): array => [],
        );
    }

    public function testTrackToolHappyPath(): void
    {
        self::initMeterForTests();
        Meter::trackTool([
            'trace_id' => 'tr_abc',
            'span_id' => 'sp_1',
            'tool_name' => 'get_weather',
            'tool_input' => ['city' => 'Paris'],
            'tool_output' => ['temp_c' => 22],
            'status' => 'success',
            'duration_ms' => 42,
            'account_id' => 'acct_1',
        ]);
        $events = self::queuedEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('tool', $event['event_type']);
        $this->assertSame('get_weather', $event['metadata']['tool_name']);
        $this->assertSame(42, $event['latency_ms']);
    }

    public function testTrackToolTagMergeDoesNotOverwrite(): void
    {
        self::initMeterForTests();
        Meter::tag(['tool_name' => 'from_tag', 'environment' => 'staging', 'user_id' => 'tag_user']);
        Meter::trackTool([
            'trace_id' => 'tr',
            'span_id' => 'sp',
            'tool_name' => 'from_kwargs',
            'status' => 'success',
            'user_id' => 'kw_user',
        ]);
        $meta = self::queuedEvents()[0]['metadata'];
        $this->assertSame('from_kwargs', $meta['tool_name']);
        $this->assertSame('kw_user', $meta['user_id']);
        $this->assertSame('staging', $meta['environment']);
    }

    public function testTrackToolRequiresInit(): void
    {
        self::resetMeterStatics();
        $this->expectException(ConfigurationException::class);
        Meter::trackTool([
            'trace_id' => 'tr',
            'span_id' => 'sp',
            'tool_name' => 't',
            'status' => 'success',
        ]);
    }
}
