<?php

declare(strict_types=1);

namespace UsageMeter\Test;

use UsageMeter\Exception\ConfigurationException;
use UsageMeter\Meter;

final class MeterInitTest extends TestCase
{
    public function testMissingBucketThrows(): void
    {
        self::resetMeterStatics();
        $this->putEnvForTest('USAGEMETER_API_KEY', 'k');
        $this->unsetEnvForTest('USAGEMETER_BUCKET');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('bucket name required');

        Meter::init([
            'verify_api_key' => false,
            'load_env_file' => false,
        ]);
    }

    public function testMeterInitSucceedsWithBucketAndKeyNoNetwork(): void
    {
        self::resetMeterStatics();

        Meter::init([
            'api_key' => 'unit-test',
            'bucket' => 'meter-bucket',
            'verify_api_key' => false,
            'verify_connection' => false,
            'load_env_file' => false,
        ]);

        $cfg = self::meterConfigSnapshot();
        $this->assertSame('meter-bucket', $cfg['bucket_name'] ?? null);
    }
}
