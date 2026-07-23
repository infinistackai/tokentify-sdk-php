<?php

declare(strict_types=1);

namespace UsageMeter\Test;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use UsageMeter\Emitter;
use UsageMeter\Meter;

/**
 * Resets {@see Meter} static state between tests (the SDK does not expose a public reset API).
 */
abstract class TestCase extends PHPUnitTestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        $this->restoreEnv();
        self::resetMeterStatics();
        parent::tearDown();
    }

    protected function backupEnv(string $name): void
    {
        if (! array_key_exists($name, $this->envBackup)) {
            $v = getenv($name);
            $this->envBackup[$name] = $v === false ? false : (string) $v;
        }
    }

    protected function putEnvForTest(string $name, string $value): void
    {
        $this->backupEnv($name);
        putenv("{$name}={$value}");
    }

    protected function unsetEnvForTest(string $name): void
    {
        $this->backupEnv($name);
        putenv($name);
    }

    private function restoreEnv(): void
    {
        foreach ($this->envBackup as $name => $previous) {
            if ($previous === false) {
                putenv($name);
            } else {
                putenv("{$name}={$previous}");
            }
        }
        $this->envBackup = [];
    }

    protected static function resetMeterStatics(): void
    {
        $ref = new ReflectionClass(Meter::class);
        foreach (['emitter', 'config', 'initialized', 'tags'] as $propName) {
            $p = $ref->getProperty($propName);
            $p->setAccessible(true);
            $default = match ($propName) {
                'emitter' => null,
                'config' => [],
                'initialized' => false,
                'tags' => [],
                default => null,
            };
            $p->setValue(null, $default);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function meterConfigSnapshot(): array
    {
        $ref = new ReflectionClass(Meter::class);
        $p = $ref->getProperty('config');
        $p->setAccessible(true);
        /** @var array<string, mixed> */
        return (array) $p->getValue();
    }

    protected static function initMeterForTests(array $extra = []): void
    {
        self::resetMeterStatics();
        Meter::init(array_merge([
            'api_key' => 'unit-test',
            'bucket' => 'test-bucket',
            'verify_api_key' => false,
            'verify_connection' => false,
            'load_env_file' => false,
            'auto_flush_on_batch' => false,
        ], $extra));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function queuedEvents(): array
    {
        $ref = new ReflectionClass(Meter::class);
        $p = $ref->getProperty('emitter');
        $p->setAccessible(true);
        $emitter = $p->getValue();
        if (! $emitter instanceof Emitter) {
            return [];
        }
        $queueRef = new ReflectionClass(Emitter::class);
        $q = $queueRef->getProperty('queue');
        $q->setAccessible(true);
        /** @var list<array<string, mixed>> */
        return (array) $q->getValue($emitter);
    }
}
