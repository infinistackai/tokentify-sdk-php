<?php

declare(strict_types=1);

namespace Tokentify\Test;

use Tokentify\AgentRun;
use Tokentify\Tokentify;

final class AgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::initForTests();
    }

    public function testAgentRunCallMetersAndReturnsResult(): void
    {
        $run = Tokentify::agentRun();
        $this->assertMatchesRegularExpression('/^tr_[0-9a-f]{16}$/', $run->traceId);
        $result = $run->call('get_weather', ['city' => 'Paris'], fn () => ['temp' => 21]);
        $this->assertSame(['temp' => 21], $result);

        $meta = self::lastEvent()['metadata'];
        $this->assertSame('get_weather', $meta['tool_name']);
        $this->assertSame($run->traceId, $meta['trace_id']);
        $this->assertMatchesRegularExpression('/^sp_[0-9a-f]{12}$/', $meta['span_id']);
        $this->assertSame(['city' => 'Paris'], $meta['tool_input']);
        $this->assertSame('success', $meta['status']);
    }

    public function testAgentRunRecordsErrorsAndRethrows(): void
    {
        $run = Tokentify::agentRun('tr_fixed', 'sp_parent');
        try {
            $run->call('flaky', [], function (): void {
                throw new \RuntimeException('down');
            });
            $this->fail('expected rethrow');
        } catch (\RuntimeException $e) {
            $this->assertSame('down', $e->getMessage());
        }
        $meta = self::lastEvent()['metadata'];
        $this->assertSame('error', $meta['status']);
        $this->assertSame(['error' => 'down'], $meta['tool_output']);
        $this->assertSame('tr_fixed', $meta['trace_id']);
        $this->assertSame('sp_parent', $meta['parent_span_id']);
    }

    public function testRecordHostedTool(): void
    {
        $payload = Tokentify::agentRun()->record('web_search', ['q' => 'x'], ['hits' => 3], 'success', 120);
        $this->assertTrue($payload['sent']);
        $this->assertSame(120, self::lastEvent()['latency_ms']);
    }

    public function testToolWrapperSharesActiveRunTraceId(): void
    {
        $lookup = Tokentify::tool(fn (string $city, string $unit = 'c') => "{$city}:{$unit}", 'lookup');
        $run = Tokentify::agentRun();
        $out = $run->run(fn () => $lookup('Paris', unit: 'f'));
        $this->assertSame('Paris:f', $out);
        $meta = self::lastEvent()['metadata'];
        $this->assertSame('lookup', $meta['tool_name']);
        $this->assertSame($run->traceId, $meta['trace_id']);
        $this->assertSame(['args' => ['Paris'], 'unit' => 'f'], $meta['tool_input']);
        $this->assertNull(AgentRun::currentTraceId());
    }

    public function testToolWrapperNamesFunctions(): void
    {
        $wrapped = Tokentify::tool('strtoupper');
        $this->assertSame('ABC', $wrapped('abc'));
        $this->assertSame('strtoupper', self::lastEvent()['metadata']['tool_name']);
    }

    public function testToolWrapperRequiresNameForClosures(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tokentify::tool(fn () => 1);
    }

    public function testRecordToolEventNeverThrows(): void
    {
        $payload = AgentRun::recordToolEvent(
            toolName: 't',
            toolInput: [],
            toolOutput: null,
            status: 'success',
            trackToolFn: function (): void {
                throw new \RuntimeException('emit failed');
            },
        );
        $this->assertFalse($payload['sent']);
        $this->assertSame('emit failed', $payload['send_error']);
    }
}
