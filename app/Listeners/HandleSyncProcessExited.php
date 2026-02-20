<?php

namespace App\Listeners;

use App\Models\SyncLog;
use Native\Desktop\Events\ChildProcess\ProcessExited;

class HandleSyncProcessExited
{
    /**
     * Handle the exit of a sync ChildProcess and update the SyncLog status.
     */
    public function handle(ProcessExited $event): void
    {
        $syncLog = $this->resolveSyncLog($event->alias);

        if (! $syncLog) {
            return;
        }

        if ($event->code === 0) {
            $syncLog->markCompleted();
        } else {
            $syncLog->markFailed();
        }
    }

    private function resolveSyncLog(string $alias): ?SyncLog
    {
        if (! str_starts_with($alias, 'sync-')) {
            return null;
        }

        $id = (int) substr($alias, strlen('sync-'));

        /** @var SyncLog|null $log */
        $log = SyncLog::find($id);

        // Only update if still running (guard against double-marking from command exit)
        if ($log && ! in_array($log->status, ['completed', 'failed'])) {
            return $log;
        }

        return null;
    }
}
