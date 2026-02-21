<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunSyncCommand extends Command
{
    protected $signature = 'sitesync:run {syncLog : The SyncLog ID to execute}';

    protected $description = 'Execute a pending sync job identified by its SyncLog ID';

    public function handle(SyncService $syncService): int
    {
        Log::info('RunSyncCommand starting', ['syncLog' => $this->argument('syncLog')]);

        /** @var SyncLog|null $log */
        $log = SyncLog::find($this->argument('syncLog'));

        if (! $log) {
            $this->error('SyncLog not found.');

            return self::FAILURE;
        }

        $from = $log->fromEnvironment;
        $to = $log->toEnvironment;

        if (! $from || ! $to) {
            $this->error('Invalid environments on SyncLog.');
            $log->markFailed();

            return self::FAILURE;
        }

        $this->line("=== SiteSync: {$from->name} → {$to->name} ===");
        $this->line('Scope: '.implode(', ', (array) $log->scope));
        $this->newLine();

        $log->markRunning();

        try {
            $syncService
                ->withLog($log)
                ->{$log->direction}($from, $to, (array) $log->scope);

            $log->markCompleted();

            $this->newLine();
            $this->info('✓ Sync completed successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $log->appendOutput("\n\n[ERROR] ".$e->getMessage());
            $log->markFailed();

            $this->newLine();
            $this->error('✗ Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
