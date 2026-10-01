<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot;

/**
 * PSR-4 autoload for WHMCS, which does not run Composer at runtime.
 * Tests use the Composer autoload instead; both map the same prefix.
 */
final class Autoload
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        spl_autoload_register(static function (string $class): void {
            $prefix = 'VogueHosting\\SupportBot\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
        self::$registered = true;
    }
}

Autoload::register();
