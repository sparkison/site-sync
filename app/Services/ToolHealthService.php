<?php

namespace App\Services;

use App\Models\SyncLog;
use Symfony\Component\Process\Process;

class ToolHealthService
{
    /**
     * All tools we know about.
     *
     * @var array<string, array{label: string, description: string, required: bool}>
     */
    private array $tools = [
        'rsync' => [
            'label' => 'rsync',
            'description' => 'Required for file sync',
            'required' => true,
        ],
        'ssh' => [
            'label' => 'ssh',
            'description' => 'Required for remote connections',
            'required' => true,
        ],
        'gzip' => [
            'label' => 'gzip',
            'description' => 'Required for database backups',
            'required' => true,
        ],
        'wp' => [
            'label' => 'WP-CLI',
            'description' => 'Required for WP-CLI database sync',
            'required' => false,
        ],
        'mysqldump' => [
            'label' => 'mysqldump',
            'description' => 'Required for mysqldump database export',
            'required' => false,
        ],
        'mysql' => [
            'label' => 'mysql',
            'description' => 'Required for mysqldump database import',
            'required' => false,
        ],
    ];

    /**
     * Check all tools and return their status.
     *
     * @return array<int, array{key: string, label: string, description: string, required: bool, installed: bool, path: string|null}>
     */
    public function check(): array
    {
        return array_values(array_map(
            fn (string $key, array $tool): array => [
                'key' => $key,
                'label' => $tool['label'],
                'description' => $tool['description'],
                'required' => $tool['required'],
                ...$this->probe($key),
            ],
            array_keys($this->tools),
            $this->tools,
        ));
    }

    /**
     * Return the names of tools required for a specific sync that are not installed.
     *
     * @return string[]
     */
    public function missingForSync(SyncLog $log): array
    {
        $needed = $this->toolsNeededForSync($log);
        $results = $this->check();

        return array_values(
            array_map(
                fn (array $t): string => $t['label'],
                array_filter($results, fn (array $t): bool => in_array($t['key'], $needed) && ! $t['installed']),
            )
        );
    }

    /**
     * Whether all always-required tools are present.
     */
    public function isHealthy(): bool
    {
        foreach ($this->check() as $tool) {
            if ($tool['required'] && ! $tool['installed']) {
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{installed: bool, path: string|null}
     */
    private function probe(string $binary): array
    {
        $process = new Process(['which', $binary], timeout: 5);
        $process->run();

        if ($process->isSuccessful()) {
            return ['installed' => true, 'path' => trim($process->getOutput())];
        }

        return ['installed' => false, 'path' => null];
    }

    /**
     * Determine which tool keys are needed for a given sync job.
     *
     * @return string[]
     */
    private function toolsNeededForSync(SyncLog $log): array
    {
        $needed = [];
        $scope = $log->scope ?? [];
        $from = $log->fromEnvironment;
        $to = $log->toEnvironment;
        $adapter = $log->site->sql_adapter ?? 'wpcli';

        $hasRemote = ! $from->is_local || ! $to->is_local;
        $hasFiles = (bool) array_intersect($scope, ['themes', 'plugins', 'mu-plugins', 'uploads', 'core', 'files', 'all']);
        $hasDb = in_array('db', $scope) || in_array('all', $scope);

        if ($hasRemote) {
            $needed[] = 'ssh';
        }

        if ($hasFiles || $hasRemote) {
            $needed[] = 'rsync';
        }

        if ($hasDb) {
            $needed[] = 'gzip';

            if ($adapter === 'wpcli') {
                $needed[] = 'wp';
            } else {
                $needed[] = 'mysqldump';
                $needed[] = 'mysql';
            }
        }

        return array_unique($needed);
    }
}
