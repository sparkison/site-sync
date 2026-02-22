<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncRunCommand extends Command
{
    protected $signature = 'sitesync:run {syncLog : The SyncLog ID to execute}';

    protected $description = 'Execute a pending sync log entry (used internally by NativePHP ChildProcess)';

    public function handle(SyncService $syncService): int
    {
        $log = SyncLog::find($this->argument('syncLog'));

        if (! $log) {
            $this->error("SyncLog #{$this->argument('syncLog')} not found.");

            return self::FAILURE;
        }

        if (! in_array($log->status, ['pending', 'failed'])) {
            $this->warn("SyncLog #{$log->id} is already {$log->status}, skipping.");

            return self::SUCCESS;
        }

        $this->line("Starting sync: {$log->fromEnvironment->name} → {$log->toEnvironment->name}");
        $this->line('Direction: '.ucfirst($log->direction).' | Scope: '.implode(', ', $log->scope));
        $this->newLine();

        $log->markRunning();

        try {
            $syncService
                ->withLog($log)
                ->{$log->direction}($log->fromEnvironment, $log->toEnvironment, $log->scope);

            $log->markCompleted();
            $this->newLine();
            $this->info('Sync completed successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $log->appendOutput("\n\n[ERROR] ".$e->getMessage());
            $log->markFailed();
            $this->newLine();
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
