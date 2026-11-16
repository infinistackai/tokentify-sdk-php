<?php

declare(strict_types=1);

namespace Tokentify\Test;

use Tokentify\Exception\ConfigurationException as NewConfigurationException;
use Tokentify\Tokentify as Sdk;
use UsageMeter\Exception\ConfigurationException;
use UsageMeter\Meter;
use UsageMeter\Tokentify as LegacyTokentify;

/** The pre-0.5.0 `UsageMeter\` API keeps working on top of the new SDK. */
final class LegacyTest extends TestCase
{
    public function testMeterInitAndTrackDelegate(): void
    {
        Meter::init(['bucket' => 'legacy', 'verify_api_key' => false, 'auto_flush_on_batch' => false, 'app_name' => 'old-app']);
        $this->assertTrue(Meter::isInitialized());
        $this->assertTrue(Sdk::isInitialized());

        Meter::tag(['account_id' => 'acct_1']);
        Meter::track(['provider' => 'openai', 'model' => 'gpt-4o', 'input_tokens' => 5, 'output_tokens' => 1]);
        $event = self::lastEvent();
        $this->assertSame('legacy', $event['bucket_name']);
        $this->assertSame('old-app', $event['app_name']);
        $this->assertSame('acct_1', $event['metadata']['account_id']);
        $this->assertSame(5, $event['input_tokens']);
    }

    public function testMeterTrackWithResponseBody(): void
    {
        Meter::init(['bucket' => 'legacy', 'verify_api_key' => false]);
        Meter::track(['provider' => 'anthropic', 'model' => 'claude', 'response_body' => self::fixture('anthropic_messages_usage.json')]);
        $this->assertSame(1000, self::lastEvent()['input_tokens']);
    }

    public function testMeterTrackSms(): void
    {
        Meter::init(['bucket' => 'legacy', 'verify_api_key' => false]);
        Meter::trackSms(['segments' => 3, 'provider' => 'twilio']);
        $event = self::lastEvent();
        $this->assertSame('twilio', $event['provider']);
        $this->assertSame('outbound', $event['model']);
        $this->assertSame(3, $event['input_tokens']);
    }

    public function testMeterTrackWithVendorMetadata(): void
    {
        Meter::init(['bucket' => 'legacy', 'verify_api_key' => false]);
        Meter::trackWithVendorMetadata(
            ['provider' => 'openai', 'model' => 'gpt-4o', 'input_tokens' => 1, 'metadata' => ['user_id' => 'override']],
            ['account_id' => 'a1', 'user_id' => 'vendor'],
        );
        $meta = self::lastEvent()['metadata'];
        $this->assertSame('a1', $meta['account_id']);
        $this->assertSame('override', $meta['user_id']);
    }

    public function testMeterRejectsApiKeyOption(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('TOKENTIFY_API_KEY');
        Meter::init(['api_key' => 'um_old', 'bucket' => 'b']);
    }

    public function testLegacyTokentifyRequiresAccountAndUserFields(): void
    {
        try {
            LegacyTokentify::init('b', ['account_id'], ['verify_api_key' => false]);
            $this->fail('expected tracking_fields error');
        } catch (NewConfigurationException $e) {
            $this->assertStringContainsString('user_id', $e->getMessage());
        }
        LegacyTokentify::init('b', ['account_id', 'user_id'], ['verify_api_key' => false]);
        Meter::trackLlm(['provider' => 'openai', 'model' => 'gpt-4o', 'input_tokens' => 1, 'metadata' => ['account_id' => 'a', 'user_id' => 'u']]);
        $this->assertSame('account_id', self::lastEvent()['metadata']['group_key_1']);
    }

    public function testLegacyClassesAliasTheNewOnes(): void
    {
        $this->assertSame(\Tokentify\TokenUsage::class, (new \ReflectionClass(\UsageMeter\TokenUsage::class))->getName());
        $this->assertInstanceOf(ConfigurationException::class, NewConfigurationException::missingBucket());
        $this->assertSame(1, \UsageMeter\UsageParser::fromProviderResponse('{"usage":{"input_tokens":1}}', 'anthropic')->inputTokens);
    }
}
