<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncPushCommand extends Command
{
    protected $signature = 'sitesync:push
        {site : Site ID or name}
        {--from=local : Source environment name}
        {--to= : Target environment name (required)}
        {--db : Sync database}
        {--files : Sync WordPress files (themes/plugins/uploads)}
        {--core : Sync WordPress core}
        {--all : Sync everything (db + files + core)}';

    protected $description = 'Push WordPress site content from one environment to another';

    public function handle(SyncService $syncService): int
    {
        $site = $this->resolveSite($this->argument('site'));

        if (! $site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $fromName = $this->option('from');
        $toName = $this->option('to');

        if (! $toName) {
            $this->error('--to option is required. Specify a target environment name.');

            return self::FAILURE;
        }

        $from = $site->environments()->where('name', $fromName)->first();
        $to = $site->environments()->where('name', $toName)->first();

        if (! $from) {
            $this->error("Source environment '{$fromName}' not found for site '{$site->name}'.");

            return self::FAILURE;
        }

        if (! $to) {
            $this->error("Target environment '{$toName}' not found for site '{$site->name}'.");

            return self::FAILURE;
        }

        $scope = $this->resolveScope();

        $this->info("Pushing [{$site->name}]: {$fromName} → {$toName}");
        $this->info('Scope: ' . implode(', ', $scope));
        $this->newLine();

        try {
            $syncService->push($from, $to, $scope);
            $this->newLine();
            $this->info('Push completed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Push failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveSite(string $identifier): ?Site
    {
        if (is_numeric($identifier)) {
            return Site::find($identifier);
        }

        return Site::where('name', $identifier)->first();
    }

    private function resolveScope(): array
    {
        if ($this->option('all')) {
            return ['all'];
        }

        $scope = [];

        if ($this->option('db')) {
            $scope[] = 'db';
        }
        if ($this->option('files')) {
            $scope[] = 'files';
        }
        if ($this->option('core')) {
            $scope[] = 'core';
        }

        return empty($scope) ? ['all'] : $scope;
    }
}
