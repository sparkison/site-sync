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
 * Lightweight polling endpoint for the live terminal widget.
 *
 * The client sends ?log_id=N&offset=M and receives only the bytes written
 * since offset M. When the log ID has changed (new sync), offset resets to 0
 * and the full content of the new log is returned.
 */
Route::get('/api/sync-logs/latest', function (Request $request) {
    $log = SyncLog::with(['site', 'fromEnvironment', 'toEnvironment'])
        ->latest()
        ->first();

    if (! $log) {
        return response()->json(null);
    }

    $clientLogId = (int) $request->query('log_id', 0);
    $offset = $clientLogId === $log->id ? (int) $request->query('offset', 0) : 0;

    ['content' => $content, 'offset' => $newOffset] = $log->readOutputChunk($offset);

    return response()->json([
        'id' => $log->id,
        'status' => $log->status,
        'direction' => $log->direction,
        'from' => $log->fromEnvironment?->name,
        'to' => $log->toEnvironment?->name,
        'site_name' => $log->site?->name,
        'site_url' => $log->site ? SiteResource::getUrl('edit', ['record' => $log->site]) : null,
        'content' => $content,
        'offset' => $newOffset,
        'started_at' => $log->started_at?->format('H:i:s'),
        'duration' => $log->completed_at ? $log->started_at->diffForHumans($log->completed_at, true) : null,
    ]);
})->name('sync-logs.latest');
