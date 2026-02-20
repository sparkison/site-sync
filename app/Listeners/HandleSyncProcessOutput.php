<?php

namespace App\Listeners;

use App\Models\SyncLog;
use Native\Desktop\Events\ChildProcess\ErrorReceived;
use Native\Desktop\Events\ChildProcess\MessageReceived;

class HandleSyncProcessOutput
{
    /**
     * Handle stdout output from a sync ChildProcess.
     */
    public function handle(MessageReceived|ErrorReceived $event): void
    {
        $syncLog = $this->resolveSyncLog($event->alias);

        if (! $syncLog) {
            return;
        }

        $syncLog->appendOutput((string) $event->data);
    }

    private function resolveSyncLog(string $alias): ?SyncLog
    {
        if (! str_starts_with($alias, 'sync-')) {
            return null;
        }

        $id = (int) substr($alias, strlen('sync-'));

        return SyncLog::find($id);
    }
}
