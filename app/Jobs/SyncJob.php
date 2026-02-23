<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Models\SyncLog;
use App\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Native\Desktop\Facades\Notification as DesktopNotification;
use Throwable;

class SyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly SyncLog $syncLog,
    ) {}

    public function handle(SyncService $syncService): void
    {
        $log = $this->syncLog;
        $log->markRunning();

        try {
            $from = $log->fromEnvironment;
            $to = $log->toEnvironment;
            $scope = $log->scope;

            $syncService
                ->withLog($log)
                ->{$log->direction}($from, $to, $scope);

            $log->markCompleted();

            DesktopNotification::title('Sync completed successfully.')
                ->show();

        } catch (Throwable $e) {
            $log->appendOutput("\n\n[ERROR] ".$e->getMessage());
            $log->markFailed();

            DesktopNotification::title('Sync failed: '.$e->getMessage())
                ->show();

            throw $e;
        }
    }

    public static function dispatchForSync(
        Environment $from,
        Environment $to,
        string $direction,
        array $scope
    ): SyncLog {
        $log = SyncLog::create([
            'site_id' => $from->site_id,
            'from_environment_id' => $from->id,
            'to_environment_id' => $to->id,
            'direction' => $direction,
            'scope' => $scope,
            'status' => 'pending',
        ]);

        static::dispatch($log);

        return $log;
    }
}
