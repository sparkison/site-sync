<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SiteDiscoveryService;
use Illuminate\Console\Command;

class DiscoverSiteCommand extends Command
{
    protected $signature = 'sitesync:discover
        {path : Local path to the WordPress installation}
        {--name= : Site name (defaults to directory name)}
        {--adapter=wpcli : SQL adapter to use (wpcli or mysqldump)}';

    protected $description = 'Discover a WordPress site from a local path and create it in SiteSync';

    public function handle(SiteDiscoveryService $discovery): int
    {
        $path = rtrim($this->argument('path'), '/');

        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Scanning {$path}...");

        $discovered = $discovery->discoverFromPath($path);

        $name = $this->option('name') ?: basename($path);
        $adapter = $this->option('adapter');

        $this->table(['Field', 'Value'], [
            ['WordPress Path', $discovered['wordpress_path']],
            ['DB Name', $discovered['db']['db_name'] ?? '(not found)'],
            ['DB User', $discovered['db']['db_user'] ?? '(not found)'],
            ['DB Host', $discovered['db']['db_host'] ?? '(not found)'],
            ['Environments found', count($discovered['environments'])],
        ]);

        if (! $this->confirm("Create site '{$name}' with the discovered configuration?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $site = Site::create([
            'name' => $name,
            'sql_adapter' => $adapter,
        ]);

        $this->info("Created site: {$site->name} (ID: {$site->id})");

        if (! empty($discovered['environments'])) {
            foreach ($discovered['environments'] as $envData) {
                $envData['site_id'] = $site->id;
                $site->environments()->create($envData);
                $this->line("  + Environment: {$envData['name']}");
            }
        } else {
            // Create a default local environment from wp-config.php data
            $localData = array_merge([
                'site_id' => $site->id,
                'name' => 'local',
                'is_local' => true,
                'wordpress_path' => $discovered['wordpress_path'],
            ], $discovered['db']);

            $site->environments()->create($localData);
            $this->line('  + Environment: local (from wp-config.php)');
        }

        $this->newLine();
        $this->info("Site '{$site->name}' created successfully. Manage it at /admin/sites/{$site->id}/edit");

        return self::SUCCESS;
    }
}
