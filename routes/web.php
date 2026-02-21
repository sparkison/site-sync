<?php

use App\Models\Site;
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
