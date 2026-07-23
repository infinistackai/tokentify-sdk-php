<?php

declare(strict_types=1);

namespace UsageMeter\Test;

use UsageMeter\UsageParser;

final class UsageParserTest extends TestCase
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

    public function testOpenAiCachedPromptTokens(): void
    {
        $body = self::loadFixture('openai_chat_cached.json');
        $usage = UsageParser::fromProviderResponse($body, 'openai');
        $this->assertSame(400, $usage->inputTokens);
        $this->assertSame(420, $usage->outputTokens);
        $this->assertSame(800, $usage->cacheReadTokens);
        $this->assertSame(0, $usage->cacheWriteTokens);
        $this->assertSame(1620, $usage->totalTokens());
    }

    public function testAnthropicCacheReadAndWriteTokens(): void
    {
        $body = self::loadFixture('anthropic_messages_usage.json');
        $usage = UsageParser::fromProviderResponse($body, 'anthropic');
        $this->assertSame(1000, $usage->inputTokens);
        $this->assertSame(200, $usage->outputTokens);
        $this->assertSame(500, $usage->cacheReadTokens);
        $this->assertSame(80, $usage->cacheWriteTokens);
        $this->assertSame(1780, $usage->totalTokens());
    }
}
