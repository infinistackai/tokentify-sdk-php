<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Environment helpers and a small built-in `.env` loader (no vlucas/phpdotenv needed).
 *
 * Values already in the environment are never overridden, like python-dotenv's `override=False`.
 */
final class Env
{
    /** @var list<string> */
    public const API_KEY = [
        'TOKENTIFY_API_KEY',
        'TOKENTIFY_TOKEN',
        // Legacy names from before the Tokentify rename; still read so existing .env files keep working.
        'USAGEMETER_API_KEY',
        'UM_API_KEY',
        'USAGEMETER_TOKEN',
        'UM_TOKEN',
    ];

    /** @var list<string> */
    public const BUCKET = ['TOKENTIFY_BUCKET', 'USAGEMETER_BUCKET'];

    /** @var list<string> */
    public const COLLECTOR = ['TOKENTIFY_COLLECTOR_URL', 'USAGEMETER_COLLECTOR_URL', 'COLLECTOR_URL'];

    /** @var list<string> */
    public const ENVIRONMENT = ['TOKENTIFY_ENVIRONMENT', 'USAGEMETER_ENVIRONMENT'];

    /** @var list<string> */
    public const APP_NAME = ['TOKENTIFY_APP_NAME', 'USAGEMETER_APP_NAME'];

    /** @var list<string> */
    public const DOTENV_PATH = ['TOKENTIFY_DOTENV_PATH', 'USAGEMETER_DOTENV_PATH'];

    /** @var list<string> */
    public const CONTEXT_LIMIT_KEY = ['TOKENTIFY_CONTEXT_LIMIT_KEY', 'USAGEMETER_CONTEXT_LIMIT_KEY'];

    /** @var list<string> */
    public const VERIFY = ['TOKENTIFY_VERIFY', 'USAGEMETER_VERIFY'];

    /** @var list<string> */
    public const LOAD_DOTENV = ['TOKENTIFY_LOAD_DOTENV', 'USAGEMETER_LOAD_DOTENV'];

    /** @var list<string> */
    public const DEBUG = ['TOKENTIFY_DEBUG', 'USAGEMETER_DEBUG'];

    /** @var list<string> */
    public const PROVIDER_BUCKETS = ['TOKENTIFY_PROVIDER_BUCKETS', 'USAGEMETER_PROVIDER_BUCKETS'];

    /** @var list<string> */
    public const AUTO_SETUP = ['TOKENTIFY_AUTO_SETUP'];

    /** One variable: `$_ENV`, then `$_SERVER`, then getenv(). Null when unset or blank. */
    public static function get(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * First non-blank variable among $names.
     *
     * @param list<string> $names
     */
    public static function first(array $names): ?string
    {
        foreach ($names as $name) {
            $value = self::get($name);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<string> $names
     */
    public static function bool(array $names, bool $default): bool
    {
        $raw = self::first($names);
        if ($raw === null) {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Load a `.env` file without overriding existing variables.
     *
     * $path, else `TOKENTIFY_DOTENV_PATH`, else the first `.env` found searching upward from the
     * current working directory. Returns the file loaded, or null when none was found.
     */
    public static function load(?string $path = null): ?string
    {
        $file = $path ?? self::first(self::DOTENV_PATH) ?? self::find();
        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return null;
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        foreach (self::parse($contents) as $name => $value) {
            if (self::exists($name)) {
                continue;
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return $file;
    }

    /** Search upward from $dir (default: cwd) for a `.env` file. */
    public static function find(?string $dir = null): ?string
    {
        $dir = $dir ?? (getcwd() ?: null);
        while ($dir !== null && $dir !== '') {
            $candidate = $dir . DIRECTORY_SEPARATOR . '.env';
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Parse `.env` text: `KEY=value`, optional `export `, single / double quotes, `#` comments.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($line, 0, $eq));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
                continue;
            }
            $raw = trim(substr($line, $eq + 1));
            $quote = $raw[0] ?? '';
            if (($quote === '"' || $quote === "'") && ($end = strpos($raw, $quote, 1)) !== false) {
                $value = substr($raw, 1, $end - 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $value);
                }
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));
            }
            $values[$name] = $value;
        }

        return $values;
    }

    private static function exists(string $name): bool
    {
        return array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER) || getenv($name) !== false;
    }
}
