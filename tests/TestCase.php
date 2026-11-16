<?php

declare(strict_types=1);

namespace Tokentify\Test;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Tokentify\Tokentify;

/**
 * Isolates every test: a fake collector (no network), a test API key, no `.env` discovery and
 * no import-time auto setup unless a test asks for it.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /** Every TOKENTIFY_* / USAGEMETER_* variable the SDK reads; cleared before each test. */
    private const MANAGED_ENV = [
        'TOKENTIFY_API_KEY', 'TOKENTIFY_TOKEN', 'USAGEMETER_API_KEY', 'UM_API_KEY', 'USAGEMETER_TOKEN', 'UM_TOKEN',
        'TOKENTIFY_BUCKET', 'USAGEMETER_BUCKET', 'TOKENTIFY_COLLECTOR_URL', 'USAGEMETER_COLLECTOR_URL', 'COLLECTOR_URL',
        'TOKENTIFY_ENVIRONMENT', 'USAGEMETER_ENVIRONMENT', 'TOKENTIFY_APP_NAME', 'USAGEMETER_APP_NAME',
        'TOKENTIFY_DOTENV_PATH', 'USAGEMETER_DOTENV_PATH', 'TOKENTIFY_CONTEXT_LIMIT_KEY', 'USAGEMETER_CONTEXT_LIMIT_KEY',
        'TOKENTIFY_VERIFY', 'USAGEMETER_VERIFY', 'TOKENTIFY_LOAD_DOTENV', 'USAGEMETER_LOAD_DOTENV',
        'TOKENTIFY_DEBUG', 'USAGEMETER_DEBUG', 'TOKENTIFY_PROVIDER_BUCKETS', 'USAGEMETER_PROVIDER_BUCKETS',
        'TOKENTIFY_AUTO_SETUP',
    ];

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $envBackup = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    protected array $requests = [];

    /** @var list<array{0: ?int, 1: array<string, string>, 2: string}> Queued responses; default 200 */
    protected array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::MANAGED_ENV as $name) {
            $this->unsetEnv($name);
        }
        $this->setEnv('TOKENTIFY_API_KEY', 'um_test_key');
        $this->setEnv('TOKENTIFY_AUTO_SETUP', 'false');
        $this->setEnv('TOKENTIFY_DOTENV_PATH', __DIR__ . '/fixtures/does-not-exist.env');

        Tokentify::reset();
        $this->requests = [];
        $this->responses = [];
        Tokentify::setHttpHandler(function (string $method, string $url, array $headers, ?string $body, float $timeout): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

            return array_shift($this->responses) ?? [200, [], '{"accepted":true}'];
        });
    }

    protected function tearDown(): void
    {
        Tokentify::emitter()?->shutdown(0.0);
        Tokentify::reset();
        Tokentify::setHttpHandler(null);
        $this->restoreEnv();
        parent::tearDown();
    }

    protected function setEnv(string $name, string $value): void
    {
        $this->backup($name);
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    protected function unsetEnv(string $name): void
    {
        $this->backup($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    /** Initialize with test-friendly defaults (no verification, no auto flush). */
    protected static function initForTests(mixed ...$args): void
    {
        Tokentify::init(...array_merge([
            'bucket' => 'test-bucket',
            'verifyApiKey' => false,
            'autoFlushOnBatch' => false,
        ], $args));
    }

    /** @return list<array<string, mixed>> */
    protected static function events(): array
    {
        return Tokentify::emitter()?->pending() ?? [];
    }

    /** @return array<string, mixed> */
    protected static function lastEvent(): array
    {
        $events = self::events();
        self::assertNotEmpty($events, 'expected at least one queued event');

        return $events[count($events) - 1];
    }

    protected static function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    private function backup(string $name): void
    {
        if (! array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
    }

    private function restoreEnv(): void
    {
        foreach ($this->envBackup as $name => [$env, $e, $s]) {
            $env === false ? putenv($name) : putenv("{$name}={$env}");
            if ($e === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $e;
            }
            if ($s === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $s;
            }
        }
        $this->envBackup = [];
    }
}
