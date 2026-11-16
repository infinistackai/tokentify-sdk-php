<?php

declare(strict_types=1);

namespace Tokentify\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use Tokentify\Attribution;
use Tokentify\ContextLimit;
use Tokentify\Env;
use Tokentify\UsageParser;

final class UsageParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, int, int, int}> */
    public static function responses(): iterable
    {
        yield 'openai chat cached' => ['openai', '{"usage":{"prompt_tokens":1200,"completion_tokens":420,"prompt_tokens_details":{"cached_tokens":800}}}', 400, 420, 800, 0];
        yield 'openai responses api' => ['openai', '{"usage":{"input_tokens":50,"output_tokens":10,"input_tokens_details":{"cached_tokens":20}}}', 30, 10, 20, 0];
        yield 'anthropic' => ['anthropic', '{"usage":{"input_tokens":1000,"output_tokens":200,"cache_read_input_tokens":500,"cache_creation_input_tokens":80}}', 1000, 200, 500, 80];
        yield 'gemini' => ['google', '{"usageMetadata":{"promptTokenCount":12,"candidatesTokenCount":8,"cachedContentTokenCount":2}}', 10, 8, 2, 0];
        yield 'bedrock converse' => ['bedrock', '{"usage":{"inputTokens":7,"outputTokens":3}}', 7, 3, 0, 0];
        yield 'no usage' => ['openai', '{"id":"x"}', 0, 0, 0, 0];
        yield 'invalid json' => ['openai', 'not json', 0, 0, 0, 0];
    }

    #[DataProvider('responses')]
    public function testFromProviderResponse(string $provider, string $body, int $in, int $out, int $cacheRead, int $cacheWrite): void
    {
        $usage = UsageParser::fromProviderResponse($body, $provider);
        $this->assertSame([$in, $out, $cacheRead, $cacheWrite], [$usage->inputTokens, $usage->outputTokens, $usage->cacheReadTokens, $usage->cacheWriteTokens]);
    }

    public function testSseEventFormats(): void
    {
        $start = UsageParser::fromSseEvent(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 25, 'output_tokens' => 1]]], 'anthropic');
        $this->assertSame(25, $start->inputTokens);
        $openai = UsageParser::fromSseEvent(['usage' => ['prompt_tokens' => 9, 'completion_tokens' => 6]], 'openai');
        $this->assertSame(6, $openai->outputTokens);
    }

    public function testAttributionHelpers(): void
    {
        $this->assertSame('end-user', Attribution::bodyEndUser('{"model":"m","user":"end-user"}'));
        $this->assertSame('', Attribution::bodyEndUser('not json'));
        $this->assertMatchesRegularExpression('/^req_[0-9a-f]{24}$/', Attribution::newRequestId());
        $this->assertSame(['X-Tokentify-A', 'x-tokentify-b'], Attribution::tokentifyHeaderNames(['X-Tokentify-A' => [], 'Host' => [], 'x-tokentify-b' => []]));
    }

    public function testContextLimitExtraction(): void
    {
        $this->assertSame(8192, ContextLimit::extractFromResponse('{"model":"m","config":{"max_tokens":8192}}'));
        $this->assertNull(ContextLimit::extractFromResponse('{"max_tokens":0}'));
        $this->assertSame(32000, ContextLimit::extractFromResponse(['ctx' => '32000'], 'ctx'));
        $this->assertNull(ContextLimit::extractFromResponse(['ctx' => 100], 'ctx'), 'values under 1000 are not context limits');
    }

    public function testEnvParser(): void
    {
        $parsed = Env::parse("A=1\n# c\nexport B='two words'\nC=\"line\\nbreak\"\nD=val # comment\nbad line\n");
        $this->assertSame(['A' => '1', 'B' => 'two words', 'C' => "line\nbreak", 'D' => 'val'], $parsed);
    }
}
