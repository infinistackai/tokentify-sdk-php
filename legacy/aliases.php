<?php

declare(strict_types=1);

/*
 * Backward compatibility for the pre-0.5.0 `UsageMeter\` namespace.
 *
 * `UsageMeter\Meter` and `UsageMeter\Tokentify` are deprecated delegates in this folder. Every other
 * `UsageMeter\X` class (TokenUsage, UsageParser, Exception\*, ...) is an alias of `Tokentify\X`, so
 * `catch (UsageMeter\Exception\ConfigurationException $e)` still catches the new exceptions.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'UsageMeter\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;

        return;
    }
    $target = 'Tokentify\\' . $relative;
    if (class_exists($target) || interface_exists($target)) {
        class_alias($target, $class);
    }
});
