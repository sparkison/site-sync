<?php

namespace App\Providers;

use App\Listeners\HandleSyncProcessExited;
use App\Listeners\HandleSyncProcessOutput;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Events\ChildProcess\ErrorReceived;
use Native\Desktop\Events\ChildProcess\MessageReceived;
use Native\Desktop\Events\ChildProcess\ProcessExited;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MessageReceived::class, HandleSyncProcessOutput::class);
        Event::listen(ErrorReceived::class, HandleSyncProcessOutput::class);
        Event::listen(ProcessExited::class, HandleSyncProcessExited::class);
    }
}
