<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

class SiteDiscoveryService
{
    /**
     * Given a local directory path, discover wp-config.php and/or movefile.yml
     * and return a structured array of site + environment data.
     */
    public function discoverFromPath(string $path): array
    {
        $path = rtrim($path, '/');

        $result = [
            'wordpress_path' => $path,
            'db' => [],
            'environments' => [],
        ];

        // Parse wp-config.php
        $wpConfig = $this->findFile($path, 'wp-config.php');
        if ($wpConfig) {
            $result['db'] = $this->parseWpConfig($wpConfig);
        }

        // Parse movefile.yml / movefile.yaml
        foreach (['movefile.yml', 'movefile.yaml', 'Movefile', 'movefile'] as $filename) {
            $moveFile = $this->findFile($path, $filename);
            if ($moveFile) {
                $result['environments'] = $this->parseMovefileYml($moveFile);
                break;
            }
        }

        return $result;
    }

    /**
     * Search for a file by name starting at $basePath and walking up directories.
     */
    public function findFile(string $basePath, string $filename): ?string
    {
        $dir = $basePath;
        $limit = 5; // don't walk too far up

        for ($i = 0; $i < $limit; $i++) {
            $candidate = $dir.'/'.$filename;
            if (file_exists($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break; // reached filesystem root
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Parse DB constants from wp-config.php using regex.
     * Returns array with keys: db_name, db_user, db_password, db_host, db_port, db_prefix.
     */
    public function parseWpConfig(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $result = [];

        $constants = [
            'db_name' => 'DB_NAME',
            'db_user' => 'DB_USER',
            'db_password' => 'DB_PASSWORD',
            'db_host' => 'DB_HOST',
            'db_prefix' => null, // handled separately
        ];

        foreach (['db_name' => 'DB_NAME', 'db_user' => 'DB_USER', 'db_password' => 'DB_PASSWORD', 'db_host' => 'DB_HOST'] as $key => $constant) {
            if (preg_match("/define\s*\(\s*['\"]".$constant."['\"]\s*,\s*['\"]([^'\"]*)['\"].*\)/", $content, $matches)) {
                $result[$key] = $matches[1];
            }
        }

        // Table prefix
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $result['db_prefix'] = $matches[1];
        }

        // DB_HOST may contain a port (host:port)
        if (isset($result['db_host']) && str_contains($result['db_host'], ':')) {
            [$host, $port] = explode(':', $result['db_host'], 2);
            $result['db_host'] = $host;
            $result['db_port'] = (int) $port;
        }

        return $result;
    }

    /**
     * Parse a movefile.yml and return environments as an array.
     * Each key becomes an environment entry.
     */
    public function parseMovefileYml(string $filePath): array
    {
        return $this->parseMovefileContent(file_get_contents($filePath));
    }

    /**
     * Parse raw movefile YAML content and return environments as an array.
     * Each key becomes an environment entry.
     */
    public function parseMovefileContent(string $content): array
    {
        $parsed = Yaml::parse($content);

        $environments = [];
        $skip = ['global'];

        foreach ($parsed as $envName => $config) {
            if (in_array($envName, $skip) || ! is_array($config)) {
                continue;
            }

            $env = [
                'name' => $envName,
                'is_local' => $envName === 'local',
                'vhost' => $config['vhost'] ?? null,
                'wordpress_path' => $config['wordpress_path'] ?? null,
                'db_name' => $config['database']['name'] ?? null,
                'db_user' => $config['database']['user'] ?? null,
                'db_password' => $config['database']['password'] ?? null,
                'db_host' => $config['database']['host'] ?? '127.0.0.1',
                'db_port' => $config['database']['port'] ?? 3306,
                'mysqldump_options' => $config['database']['mysqldump_options'] ?? null,
                'ssh_host' => $config['ssh']['host'] ?? null,
                'ssh_user' => $config['ssh']['user'] ?? null,
                'ssh_port' => $config['ssh']['port'] ?? 22,
                'ssh_password' => $config['ssh']['password'] ?? null,
                'rsync_options' => $config['ssh']['rsync_options'] ?? null,
                'exclude' => $config['exclude'] ?? null,
            ];

            $environments[] = $env;
        }

        return $environments;
    }

    /**
     * Export current environments back to movefile.yml format.
     */
    public function exportToMovefile(array $environments): string
    {
        $data = [
            'global' => ['sql_adapter' => 'wpcli'],
        ];

        foreach ($environments as $env) {
            $envData = [
                'vhost' => $env['vhost'],
                'wordpress_path' => $env['wordpress_path'],
                'database' => [
                    'name' => $env['db_name'],
                    'user' => $env['db_user'],
                    'password' => $env['db_password'],
                    'host' => $env['db_host'],
                ],
            ];

            if (! empty($env['ssh_host'])) {
                $envData['ssh'] = [
                    'host' => $env['ssh_host'],
                    'user' => $env['ssh_user'],
                ];
                if (! empty($env['ssh_port']) && $env['ssh_port'] != 22) {
                    $envData['ssh']['port'] = $env['ssh_port'];
                }
                if (! empty($env['rsync_options'])) {
                    $envData['ssh']['rsync_options'] = $env['rsync_options'];
                }
            }

            if (! empty($env['exclude'])) {
                $envData['exclude'] = $env['exclude'];
            }

            $data[$env['name']] = $envData;
        }

        return Yaml::dump($data, 4, 2);
    }
}
