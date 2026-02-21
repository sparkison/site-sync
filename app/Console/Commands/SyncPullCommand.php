<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncPullCommand extends Command
{
    protected $signature = 'sitesync:pull
        {site : Site ID or name}
        {--from= : Source environment name (required)}
        {--to=local : Target environment name}
        {--db : Sync database}
        {--themes : Sync wp-content/themes/}
        {--plugins : Sync wp-content/plugins/}
        {--mu-plugins : Sync wp-content/mu-plugins/}
        {--uploads : Sync wp-content/uploads/}
        {--files : Sync themes, plugins and uploads (alias for --themes --plugins --uploads)}
        {--core : Sync WordPress core}
        {--all : Sync everything (db + core + themes + plugins + mu-plugins + uploads)}
        {--path=* : Arbitrary path(s) relative to the WordPress root}';

    protected $description = 'Pull WordPress site content from one environment to another';

    public function handle(SyncService $syncService): int
    {
        $site = $this->resolveSite($this->argument('site'));

        if (! $site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $fromName = $this->option('from');
        $toName = $this->option('to');

        if (! $fromName) {
            $this->error('--from option is required. Specify a source environment name.');

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

        $this->info("Pulling [{$site->name}]: {$fromName} → {$toName}");
        $this->info('Scope: '.implode(', ', $scope));
        $this->newLine();

        try {
            $syncService->pull($from, $to, $scope);
            $this->newLine();
            $this->info('Pull completed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Pull failed: '.$e->getMessage());

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
        if ($this->option('themes')) {
            $scope[] = 'themes';
        }
        if ($this->option('plugins')) {
            $scope[] = 'plugins';
        }
        if ($this->option('mu-plugins')) {
            $scope[] = 'mu-plugins';
        }
        if ($this->option('uploads')) {
            $scope[] = 'uploads';
        }
        if ($this->option('files')) {
            array_push($scope, 'themes', 'plugins', 'uploads');
        }
        if ($this->option('core')) {
            $scope[] = 'core';
        }

        foreach ($this->option('path') as $path) {
            if ($path !== '') {
                $scope[] = $path;
            }
        }

        return empty($scope) ? ['all'] : array_values(array_unique($scope));
    }
}
