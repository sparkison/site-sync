<?php

use App\Filament\Resources\SiteResource;
use App\Models\Site;
use App\Models\SyncLog;
use App\Services\SiteExportImportService;
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

Route::get('/api/sync-logs/latest', function () {
    $log = SyncLog::with(['site', 'fromEnvironment', 'toEnvironment'])->latest()->first();

    if (! $log) {
        return response()->json(null);
    }

    $output = $log->output;
    $truncated = false;

    if (mb_strlen($output) > 100_000) {
        $output = mb_substr($output, -100_000);
        // Trim to the next newline so we don't start mid-line
        $newline = strpos($output, "\n");
        if ($newline !== false) {
            $output = substr($output, $newline + 1);
        }
        $truncated = true;
    }

    return response()->json([
        'id' => $log->id,
        'status' => $log->status,
        'direction' => $log->direction,
        'from' => $log->fromEnvironment?->name,
        'to' => $log->toEnvironment?->name,
        'site_name' => $log->site?->name,
        'site_url' => $log->site ? SiteResource::getUrl('edit', ['record' => $log->site]) : null,
        'output' => $truncated ? "[Output truncated — showing last 100KB]\n\n".$output : $output,
        'started_at' => $log->started_at?->format('H:i:s'),
        'duration' => $log->completed_at ? $log->started_at->diffForHumans($log->completed_at, true) : null,
    ]);
})->name('sync-logs.latest');
