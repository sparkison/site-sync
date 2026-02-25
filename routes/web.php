<?php

use App\Filament\Resources\SiteResource;
use App\Models\Site;
use App\Models\SyncLog;
use App\Services\SiteExportImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/sites/{site}/export', function (Site $site, SiteExportImportService $service) {
    $payload = $service->export($site);
    $filename = str($site->name)->slug()->prepend('sitesync-')->append('.json')->toString();

    return response()->streamDownload(
        function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        },
        $filename,
        ['Content-Type' => 'application/json'],
    );
})->name('sites.export');

/**
 * SSE endpoint that streams sync log output in real time.
 *
 * The client sends ?log_id=N&offset=M on the initial request.
 * On automatic SSE reconnects the browser supplies Last-Event-ID: {log_id}:{offset},
 * so the stream resumes exactly where it left off.
 *
 * Event types:
 *   init   – sent on connection or when a new log is detected; contains full metadata
 *   chunk  – new output bytes appended to the log file
 *   status – status/duration change for the current log
 */
Route::get('/api/sync-logs/stream', function (Request $request) {
    // Resolve starting position from Last-Event-ID (reconnects) or query params (first load).
    $lastEventId = $request->header('Last-Event-ID', '');

    if ($lastEventId !== '') {
        [$resumeLogId, $resumeOffset] = array_map('intval', array_pad(explode(':', $lastEventId, 2), 2, 0));
    } else {
        $resumeLogId = (int) $request->query('log_id', 0);
        $resumeOffset = (int) $request->query('offset', 0);
    }

    return response()->stream(function () use ($resumeLogId, $resumeOffset): void {
        set_time_limit(0);
        ignore_user_abort(true);

        // Flush any pre-existing output buffers so echo reaches the client immediately.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $log = SyncLog::with(['site', 'fromEnvironment', 'toEnvironment'])->latest()->first();
        $isSameLog = $log && $log->id === $resumeLogId;
        $offset = $isSameLog ? $resumeOffset : 0;
        $currentLogId = $log?->id;
        $currentStatus = $log?->status;

        // Helper: emit a named SSE event.
        $send = function (array $payload, int $logId, int $fileOffset) use (&$offset): void {
            $offset = $fileOffset;
            echo "id: {$logId}:{$fileOffset}\n";
            echo 'data: '.json_encode($payload)."\n\n";
            flush();
        };

        // Initial meta event so the client knows what log we are on.
        $send([
            'type' => 'init',
            'id' => $log?->id,
            'status' => $log?->status,
            'direction' => $log?->direction,
            'from' => $log?->fromEnvironment?->name,
            'to' => $log?->toEnvironment?->name,
            'site_name' => $log?->site?->name,
            'site_url' => $log?->site ? SiteResource::getUrl('edit', ['record' => $log->site]) : null,
            'started_at' => $log?->started_at?->format('H:i:s'),
            'duration' => $log?->completed_at ? $log->started_at->diffForHumans($log->completed_at, true) : null,
        ], $currentLogId ?? 0, $offset);

        $dbCheckMs = 0;
        $heartbeatMs = 0;

        while (! connection_aborted()) {
            usleep(100_000); // 100 ms tick
            $dbCheckMs += 100;
            $heartbeatMs += 100;

            // --- File streaming (every tick) ---
            if ($log) {
                $path = $log->logFilePath();

                if (file_exists($path)) {
                    clearstatcache(true, $path);
                    $size = filesize($path);

                    if ($size > $offset) {
                        $fp = fopen($path, 'rb');
                        fseek($fp, $offset);
                        // Cap at 64 KB per event to avoid huge payloads.
                        $chunk = (string) fread($fp, min($size - $offset, 65_536));
                        fclose($fp);

                        if ($chunk !== '') {
                            $send([
                                'type' => 'chunk',
                                'id' => $log->id,
                                'content' => $chunk,
                            ], $log->id, $offset + strlen($chunk));
                        }
                    }
                }
            }

            // --- Heartbeat every 15 s to keep the connection alive through proxies ---
            if ($heartbeatMs >= 15_000) {
                echo ": heartbeat\n\n";
                flush();
                $heartbeatMs = 0;
            }

            // --- DB check every 1 s for status / new log ---
            if ($dbCheckMs < 1_000) {
                continue;
            }

            $dbCheckMs = 0;
            $fresh = SyncLog::with(['site', 'fromEnvironment', 'toEnvironment'])->latest()->first();

            if (! $fresh) {
                continue;
            }

            // New log detected — reset everything and send init.
            if ($fresh->id !== $currentLogId) {
                $log = $fresh;
                $currentLogId = $log->id;
                $currentStatus = $log->status;

                $send([
                    'type' => 'init',
                    'id' => $log->id,
                    'status' => $log->status,
                    'direction' => $log->direction,
                    'from' => $log->fromEnvironment?->name,
                    'to' => $log->toEnvironment?->name,
                    'site_name' => $log->site?->name,
                    'site_url' => $log->site ? SiteResource::getUrl('edit', ['record' => $log->site]) : null,
                    'started_at' => $log->started_at?->format('H:i:s'),
                    'duration' => null,
                ], $log->id, 0);

                continue;
            }

            // Status change for the current log.
            if ($fresh->status !== $currentStatus) {
                $currentStatus = $fresh->status;
                $log = $fresh;

                $send([
                    'type' => 'status',
                    'id' => $log->id,
                    'status' => $log->status,
                    'duration' => $log->completed_at ? $log->started_at->diffForHumans($log->completed_at, true) : null,
                ], $log->id, $offset);
            }
        }
    }, 200, [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'X-Accel-Buffering' => 'no',
    ]);
})->name('sync-logs.stream');
