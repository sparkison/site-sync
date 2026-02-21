<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Site;
use Illuminate\Support\Arr;
use RuntimeException;

class SiteExportImportService
{
    public const FORMAT_VERSION = 1;

    /**
     * Sensitive environment fields excluded from export.
     *
     * @var string[]
     */
    private array $sensitiveFields = [
        'db_password',
        'ssh_password',
        'ssh_key_id',
    ];

    /**
     * Serialize a Site and its environments to an exportable array.
     *
     * @return array{version: int, site: array<string, mixed>, environments: list<array<string, mixed>>}
     */
    public function export(Site $site): array
    {
        $site->loadMissing('environments');

        $environments = $site->environments->map(function (Environment $env): array {
            return Arr::except($env->toArray(), array_merge(
                ['id', 'site_id', 'created_at', 'updated_at'],
                $this->sensitiveFields,
            ));
        })->values()->all();

        return [
            'version' => self::FORMAT_VERSION,
            'site' => Arr::only($site->toArray(), ['name', 'sql_adapter', 'notes']),
            'environments' => $environments,
        ];
    }

    /**
     * Import a site from a decoded JSON payload.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException
     */
    public function import(array $payload): Site
    {
        $version = (int) ($payload['version'] ?? 0);

        if ($version < 1 || $version > self::FORMAT_VERSION) {
            throw new RuntimeException("Unsupported export format version: {$version}");
        }

        $siteData = $payload['site'] ?? null;

        if (! is_array($siteData) || empty($siteData['name'])) {
            throw new RuntimeException('Invalid export file: missing site data.');
        }

        $site = Site::create([
            'name' => $this->uniqueName($siteData['name']),
            'sql_adapter' => $siteData['sql_adapter'] ?? 'wpcli',
            'notes' => $siteData['notes'] ?? null,
        ]);

        foreach ($payload['environments'] ?? [] as $envData) {
            if (! is_array($envData)) {
                continue;
            }

            $site->environments()->create(Arr::except(
                $envData,
                ['id', 'site_id', 'created_at', 'updated_at', ...$this->sensitiveFields],
            ));
        }

        return $site;
    }

    /**
     * Return $name suffixed with a number if a Site with that name already exists.
     */
    private function uniqueName(string $name): string
    {
        if (! Site::where('name', $name)->exists()) {
            return $name;
        }

        $i = 2;
        while (Site::where('name', "{$name} ({$i})")->exists()) {
            $i++;
        }

        return "{$name} ({$i})";
    }
}
