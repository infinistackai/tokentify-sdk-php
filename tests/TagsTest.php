<?php

declare(strict_types=1);

namespace Tokentify\Test;

use Tokentify\Tokentify;

final class TagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::initForTests();
    }

    public function testTagScopeRestoresPreviousValues(): void
    {
        $outer = Tokentify::tag(user_id: 'u1', feature: 'chat');
        $inner = Tokentify::tag(user_id: 'u2');
        $this->assertSame('u2', Tokentify::currentTags()['user_id']);
        $inner->restore();
        $this->assertSame('u1', Tokentify::currentTags()['user_id']);
        $outer->restore();
        $outer->restore();
        $this->assertArrayNotHasKey('user_id', Tokentify::currentTags());
        $this->assertArrayNotHasKey('feature', Tokentify::currentTags());
    }

    public function testWithTagsRestoresEvenOnException(): void
    {
        try {
            Tokentify::withTags(['user_id' => 'u9'], function (): void {
                Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('u9', self::lastEvent()['metadata']['user_id']);
        $this->assertArrayNotHasKey('user_id', Tokentify::currentTags());
    }

    public function testBucketTagOverridesDefaultBucket(): void
    {
        $result = Tokentify::withTags(['bucket' => 'other'], function (): string {
            Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);

            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame('other', self::lastEvent()['bucket_name']);
    }

    public function testCurrentTagsIncludeConfigDefaults(): void
    {
        $tags = Tokentify::currentTags();
        $this->assertSame('production', $tags['environment']);
        $this->assertSame('test-bucket', $tags['bucket_name']);
    }

    public function testSessionWithCallable(): void
    {
        $value = Tokentify::session('sess_1', function (): int {
            Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);

            return 42;
        });
        $this->assertSame(42, $value);
        $this->assertSame('sess_1', self::lastEvent()['metadata']['session_id']);
        $this->assertArrayNotHasKey('session_id', Tokentify::currentTags());
    }

    public function testSessionEndOnExitDeletesSessionState(): void
    {
        $this->responses[] = [200, [], '{"cleared":true}'];
        $scope = Tokentify::session('sess 2', endOnExit: true);
        $this->assertSame('sess 2', Tokentify::currentTags()['session_id']);
        $scope->restore();
        $this->assertSame('DELETE', $this->requests[0]['method']);
        $this->assertStringEndsWith('/v1/sessions/sess%202', $this->requests[0]['url']);
    }

    public function testEndSession(): void
    {
        $this->responses[] = [200, [], '{"cleared":true}'];
        $this->assertTrue(Tokentify::endSession('abc'));
        $this->responses[] = [404, [], 'nope'];
        $this->assertFalse(Tokentify::endSession('abc'));
        $this->assertFalse(Tokentify::endSession('  '));
    }

    public function testTagRejectsPositionalValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tokentify::tag('u1');
    }
}
