<?php

declare(strict_types=1);

namespace Tokentify\Test;

use Tokentify\Exception\ConfigurationException;
use Tokentify\Exception\TransportException;
use Tokentify\Tokentify;

final class SetupTest extends TestCase
{
    public function testInitRequiresApiKey(): void
    {
        $this->unsetEnv('TOKENTIFY_API_KEY');
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('TOKENTIFY_API_KEY');
        self::initForTests();
    }

    public function testApiKeyCannotBePassedAsAnArgument(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('api_key cannot be passed');
        Tokentify::setup('b', api_key: 'um_nope');
    }

    public function testInitRejectsUnknownOptions(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("unknown option 'colour'");
        self::initForTests(colour: 'blue');
    }

    public function testInitRequiresBucketUnlessDisabled(): void
    {
        try {
            Tokentify::init(verifyApiKey: false);
            $this->fail('expected missing bucket');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('TOKENTIFY_BUCKET', $e->getMessage());
        }
        Tokentify::init(verifyApiKey: false, requireBucket: false);
        $this->assertTrue(Tokentify::isInitialized());
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);
        $this->assertArrayNotHasKey('bucket_name', self::lastEvent());
    }

    public function testSettingsFallBackToEnvironment(): void
    {
        $this->setEnv('TOKENTIFY_BUCKET', 'env-bucket');
        $this->setEnv('TOKENTIFY_APP_NAME', 'env-app');
        $this->setEnv('TOKENTIFY_ENVIRONMENT', 'staging');
        $this->setEnv('TOKENTIFY_COLLECTOR_URL', 'http://collector.test:4006/');
        Tokentify::init(verifyApiKey: false);

        $this->assertSame('http://collector.test:4006', Tokentify::emitter()?->collectorUrl());
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 3);
        $event = self::lastEvent();
        $this->assertSame('env-bucket', $event['bucket_name']);
        $this->assertSame('env-app', $event['app_name']);
        $this->assertSame('staging', $event['metadata']['environment']);
    }

    public function testDefaultCollectorUrl(): void
    {
        self::initForTests();
        $this->assertSame('http://api.tokentify.ai', Tokentify::emitter()?->collectorUrl());
    }

    public function testLegacyUsagemeterEnvNamesStillWork(): void
    {
        $this->unsetEnv('TOKENTIFY_API_KEY');
        $this->setEnv('USAGEMETER_API_KEY', 'um_legacy');
        $this->setEnv('USAGEMETER_BUCKET', 'legacy-bucket');
        Tokentify::init(verifyApiKey: false);
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);
        Tokentify::flush();
        $this->assertSame('legacy-bucket', json_decode((string) $this->requests[0]['body'], true)['events'][0]['bucket_name']);
        $this->assertSame('Bearer um_legacy', $this->requests[0]['headers']['Authorization']);
    }

    public function testVerifyApiKeyRejectsRevokedKey(): void
    {
        $this->responses[] = [401, [], '{"detail":"invalid"}'];
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('invalid or revoked API key');
        Tokentify::init(bucket: 'b');
    }

    public function testVerifyApiKeyPostsEmptyBatch(): void
    {
        Tokentify::init(bucket: 'b');
        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('http://api.tokentify.ai/v1/ingest', $this->requests[0]['url']);
        $this->assertSame('{"events":[]}', $this->requests[0]['body']);
    }

    public function testSetupVerifiesConnectionAndKeyByDefault(): void
    {
        Tokentify::setup('b');
        $this->assertSame(['GET', 'POST'], array_column($this->requests, 'method'));
        $this->assertStringEndsWith('/health', $this->requests[0]['url']);
    }

    public function testSetupVerifyCanBeTurnedOffFromEnv(): void
    {
        $this->setEnv('TOKENTIFY_VERIFY', 'false');
        Tokentify::setup('b');
        $this->assertSame([], $this->requests);
    }

    public function testUnreachableCollectorFailsVerification(): void
    {
        $this->responses[] = [null, [], 'connection refused'];
        $this->expectException(TransportException::class);
        Tokentify::setup('b', verify: true);
    }

    public function testTrackingBeforeSetupThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('before trackLlm()');
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o');
    }

    public function testEnsureSetupIsIdempotent(): void
    {
        $this->setEnv('TOKENTIFY_VERIFY', 'false');
        Tokentify::ensureSetup('b1');
        $emitter = Tokentify::emitter();
        Tokentify::ensureSetup();
        $this->assertSame($emitter, Tokentify::emitter());
        $this->assertSame('b1', Tokentify::config()['bucket_name']);
    }

    public function testReinitWithSameConfigKeepsQueue(): void
    {
        self::initForTests();
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);
        self::initForTests();
        $this->assertCount(1, self::events());
    }

    public function testLoadEnvReadsDotenvWithoutOverriding(): void
    {
        $file = sys_get_temp_dir() . '/tokentify-test-' . bin2hex(random_bytes(4)) . '.env';
        file_put_contents($file, "# comment\nexport TOKENTIFY_BUCKET=\"dotenv-bucket\"\nTOKENTIFY_API_KEY=um_from_file\nTOKENTIFY_APP_NAME='quoted app' # trailing\n");
        try {
            $this->unsetEnv('TOKENTIFY_BUCKET');
            $this->unsetEnv('TOKENTIFY_APP_NAME');
            $this->assertTrue(Tokentify::loadEnv($file));
            $this->assertSame('dotenv-bucket', getenv('TOKENTIFY_BUCKET'));
            $this->assertSame('quoted app', $_ENV['TOKENTIFY_APP_NAME']);
            $this->assertSame('um_test_key', getenv('TOKENTIFY_API_KEY'), 'existing values win');
        } finally {
            unlink($file);
        }
        $this->assertFalse(Tokentify::loadEnv($file));
    }

    public function testAutoEnableFromEnvironment(): void
    {
        $this->setEnv('TOKENTIFY_AUTO_SETUP', 'true');
        $this->assertFalse(Tokentify::isInitialized());
        $this->assertTrue(Tokentify::enabled());
        $this->assertSame([], $this->requests, 'auto setup does not verify by default');
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 2);
        $this->assertCount(1, self::events());
    }

    public function testAutoEnableNeverThrows(): void
    {
        $this->setEnv('TOKENTIFY_AUTO_SETUP', 'true');
        $this->unsetEnv('TOKENTIFY_API_KEY');
        $log = ini_set('error_log', '/dev/null');
        try {
            $this->assertFalse(Tokentify::enabled());
            $this->assertFalse(Tokentify::enabled(), 'attempted once');
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    public function testAutoEnableCanBeDisabled(): void
    {
        $this->assertFalse(Tokentify::enabled());
    }

    public function testProviderBucketsRouteEvents(): void
    {
        self::initForTests(bucket: null, requireBucket: false, providerBuckets: 'openai:openai-bucket, anthropic:claude-bucket');
        Tokentify::trackLlm(provider: 'anthropic', model: 'claude-x', input_tokens: 1);
        $this->assertSame('claude-bucket', self::lastEvent()['bucket_name']);
        Tokentify::trackLlm(provider: 'openai', model: 'gpt-4o', input_tokens: 1);
        $this->assertSame('openai-bucket', self::lastEvent()['bucket_name']);
    }

    public function testParseProviderBuckets(): void
    {
        $this->assertSame(['openai' => 'o', 'google' => 'g'], Tokentify::parseProviderBuckets('OpenAI:o, google:g,bad'));
        $this->assertSame(['openai' => 'o'], Tokentify::parseProviderBuckets(['OpenAI ' => ' o', 'x' => '']));
        $this->assertSame([], Tokentify::parseProviderBuckets(null));
    }
}
