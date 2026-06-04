<?php

declare(strict_types=1);

namespace UsageMeter\Test;

use UsageMeter\Exception\ConfigurationException;
use UsageMeter\Tokentify;

final class TokentifyInitTest extends TestCase
{
    public function testInitWithStringBucketMatchesPythonStyle(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'test-key-phpunit');

        Tokentify::init('my-bucket-phpunit', ['account_id', 'user_id'], [
            'verify_api_key' => false,
            'verify_connection' => false,
            'load_env_file' => false,
        ]);

        $cfg = self::meterConfigSnapshot();
        $this->assertSame('my-bucket-phpunit', $cfg['bucket_name'] ?? null);
        $this->assertSame(['account_id', 'user_id'], $cfg['tracking_fields'] ?? null);
    }

    public function testInitWithStringBucketAndCustomTrackingFieldsOrder(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'test-key-phpunit');

        Tokentify::init('bucket-two', ['account_id', 'user_id', 'tenant_id'], [
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);

        $cfg = self::meterConfigSnapshot();
        $this->assertSame('bucket-two', $cfg['bucket_name'] ?? null);
        $this->assertSame(['account_id', 'user_id', 'tenant_id'], $cfg['tracking_fields'] ?? null);
    }

    public function testInitWithOptionsArrayBackwardCompatible(): void
    {
        self::resetMeterStatics();

        Tokentify::init([
            'api_key' => 'explicit-key',
            'bucket' => 'legacy-bucket',
            'tracking_fields' => ['account_id', 'user_id'],
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);

        $cfg = self::meterConfigSnapshot();
        $this->assertSame('legacy-bucket', $cfg['bucket_name'] ?? null);
        $this->assertSame('explicit-key', $cfg['_api_key'] ?? null);
    }

    public function testSecondArgumentOverridesTrackingFieldsWhenUsingArrayForm(): void
    {
        self::resetMeterStatics();

        Tokentify::init([
            'api_key' => 'k',
            'bucket' => 'b',
            'tracking_fields' => ['account_id', 'user_id'],
            'verify_api_key' => false,
            'load_env_file' => false,
        ], ['org_id', 'account_id', 'user_id']);

        $cfg = self::meterConfigSnapshot();
        $this->assertSame(['org_id', 'account_id', 'user_id'], $cfg['tracking_fields'] ?? null);
    }

    public function testEmptyStringBucketThrows(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'x');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('bucket name required');

        Tokentify::init('   ', ['account_id', 'user_id'], [
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);
    }

    public function testMissingApiKeyThrows(): void
    {
        self::resetMeterStatics();
        $this->unsetEnvForTest('USAGEMETER_API_KEY');
        $this->unsetEnvForTest('UM_API_KEY');
        $this->unsetEnvForTest('USAGEMETER_TOKEN');
        $this->unsetEnvForTest('UM_TOKEN');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('API key required');

        Tokentify::init('only-bucket', ['account_id', 'user_id'], [
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);
    }

    public function testBucketFromEnvironmentWhenUsingEmptyArrayBucketKey(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'test-key-phpunit');
        $this->putEnvForTest('USAGEMETER_BUCKET', 'from-env-bucket');

        Tokentify::init([
            'bucket' => '',
            'tracking_fields' => ['account_id', 'user_id'],
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);

        $cfg = self::meterConfigSnapshot();
        $this->assertSame('from-env-bucket', $cfg['bucket_name'] ?? null);
    }

    public function testOmittingTrackingFieldsThrows(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'k');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('explicit tracking_fields');

        Tokentify::init('b', null, [
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);
    }

    public function testTrackingFieldsMissingAccountIdThrows(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'k');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('account_id');

        Tokentify::init('b', ['user_id', 'session_id'], [
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);
    }

    public function testTrackingFieldsMissingUserIdThrows(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'k');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('user_id');

        Tokentify::init('b', ['account_id', 'session_id'], [
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);
    }

    public function testTrackingFieldsViaThirdMergeArgument(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'k');

        Tokentify::init('my-bucket', null, [
            'tracking_fields' => ['account_id', 'user_id'],
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);

        $cfg = self::meterConfigSnapshot();
        $this->assertSame(['account_id', 'user_id'], $cfg['tracking_fields'] ?? null);
    }
}
