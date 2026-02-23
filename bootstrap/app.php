<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// When running as a packaged NativePHP app, redirect bootstrap caches to the
// writable userData path so the signed .app bundle is never modified on startup.
if (! empty($_ENV['NATIVEPHP_RUNNING']) && ! empty($_ENV['NATIVEPHP_USER_DATA_PATH'])) {
    $bootstrapCache = $_ENV['NATIVEPHP_USER_DATA_PATH'].'/bootstrap/cache';

    if (! is_dir($bootstrapCache)) {
        @mkdir($bootstrapCache, 0755, true);
    }

    $_ENV['APP_CONFIG_CACHE'] = $bootstrapCache.'/config.php';
    $_ENV['APP_PACKAGES_CACHE'] = $bootstrapCache.'/packages.php';
    $_ENV['APP_SERVICES_CACHE'] = $bootstrapCache.'/services.php';
    $_ENV['APP_ROUTES_CACHE'] = $bootstrapCache.'/routes-v7.php';
    $_ENV['APP_EVENTS_CACHE'] = $bootstrapCache.'/events.php';
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
