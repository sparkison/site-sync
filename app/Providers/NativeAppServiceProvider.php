<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open()
            ->title('SiteSync')
            ->width(1200)
            ->height(800)
            ->minWidth(1024)
            ->minHeight(750)
            ->titleBarHiddenInset();

        // Start a persistent queue worker so dispatched jobs are processed
        // while the app is running. persistent(true) ensures NativePHP
        // automatically restarts it if it exits unexpectedly.
        ChildProcess::artisan(
            cmd: ['queue:work', '--sleep=3', '--tries=1', '--timeout=3600'],
            alias: 'queue-worker',
            persistent: true,
        );
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [];
    }
}
