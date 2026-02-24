<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\SyncLog;
use Symfony\Component\Process\Process;

class SyncService
{
    private ?SyncLog $log = null;

    private array $defaultExcludes = [
        '.DS_Store',
        '.git/',
        '.gitignore',
        '.gitmodules',
        '.env',
        'node_modules/',
        'movefile.yml',
        'movefile.yaml',
        'Movefile',
        'wp-config.php',
        'wp-content/*.sql.gz',
        '*.orig',
    ];

    public function withLog(SyncLog $log): static
    {
        $this->log = $log;

        return $this;
    }

    /**
     * Push: local (source) → remote (target).
     */
    public function push(Environment $source, Environment $target, array $scope): void
    {
        $this->runSync($source, $target, 'push', $scope);
    }

    /**
     * Pull: remote (source) → local (target).
     */
    public function pull(Environment $source, Environment $target, array $scope): void
    {
        $this->runSync($source, $target, 'pull', $scope);
    }

    private const KNOWN_SCOPES = ['db', 'core', 'themes', 'plugins', 'mu-plugins', 'uploads', 'files'];

    private function runSync(Environment $from, Environment $to, string $direction, array $scope): void
    {
        if (in_array('all', $scope)) {
            $scope = ['db', 'core', 'themes', 'plugins', 'mu-plugins', 'uploads'];
        }

        // Expand legacy 'files' to granular wp-content directories
        if (in_array('files', $scope)) {
            $scope = array_filter($scope, fn (string $s): bool => $s !== 'files');
            $scope = array_merge(array_values($scope), ['themes', 'plugins', 'uploads']);
        }

        $scope = array_unique($scope);

        if (in_array('db', $scope)) {
            $this->output("=== Syncing Database ===\n");
            $this->syncDatabase($from, $to, $direction);
        }

        if (in_array('core', $scope)) {
            $this->output("=== Syncing WordPress Core ===\n");
            $this->syncCore($from, $to, $direction);
        }

        foreach (['themes', 'plugins', 'mu-plugins', 'uploads'] as $wpContentDir) {
            if (in_array($wpContentDir, $scope)) {
                $this->output("=== Syncing wp-content/{$wpContentDir}/ ===\n");
                $this->syncWpContentDirectory($from, $to, $wpContentDir);
            }
        }

        foreach (array_diff($scope, self::KNOWN_SCOPES) as $customPath) {
            $this->output("=== Syncing custom path: {$customPath} ===\n");
            $this->syncCustomPath($from, $to, $customPath);
        }
    }

    private function syncDatabase(Environment $from, Environment $to, string $direction): void
    {
        $sqlAdapter = $from->site->sql_adapter;
        $timestamp = time();
        $backupBasename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $to->name)).'-backup-'.$timestamp;

        // 1. Backup destination before overwriting
        $this->backupDatabase($to, $sqlAdapter, $backupBasename);

        // 2. Determine the destination's intended URL for search-replace
        $destinationUrl = null;

        if ($sqlAdapter === 'wpcli') {
            if ($to->vhost) {
                $destinationUrl = rtrim($to->vhost, '/');
                $this->output("  Destination URL (from vhost): {$destinationUrl}\n\n");
            } else {
                $this->output("  Detecting {$to->name} site URL from database...\n");
                $destinationUrl = $this->getWordPressSiteUrl($to);
                $this->output($destinationUrl
                    ? "  → {$destinationUrl}\n  ⚠ Tip: set the Site URL / Vhost on this environment for reliable search-replace.\n\n"
                    : "  → Could not detect URL — search-replace will be skipped\n\n"
                );
            }
        }

        // 3. Dump source and import to destination
        $this->output("  Dumping {$from->name} and importing into {$to->name}...\n\n");

        $dumpCmd = $this->buildDumpCommand($from, $sqlAdapter);
        $importCmd = $this->buildImportCommand($to, $sqlAdapter);

        if ($from->is_local && $to->is_local) {
            $this->runProcess(['bash', '-c', "{$dumpCmd} | {$importCmd}"]);
        } elseif ($from->is_local && ! $to->is_local) {
            $sshCmd = $this->buildSshCommand($to);
            $this->runProcess(['bash', '-c', "{$dumpCmd} | {$sshCmd} '{$importCmd}'"]);
        } elseif (! $from->is_local && $to->is_local) {
            $sshCmd = $this->buildSshCommand($from);
            $this->runProcess(['bash', '-c', "{$sshCmd} '{$dumpCmd}' | {$importCmd}"]);
        } else {
            throw new \RuntimeException('Remote-to-remote database sync is not supported. Use a local environment as intermediary.');
        }

        // 4. wp search-replace: swap imported source URL back to destination URL
        if ($destinationUrl && $sqlAdapter === 'wpcli') {
            $importedUrl = $this->getWordPressSiteUrl($to);

            if ($importedUrl && $importedUrl !== $destinationUrl) {
                $this->output("\n  Running wp search-replace (URL)\n");
                $this->output("  {$importedUrl} → {$destinationUrl}\n\n");
                $this->runSearchReplace($to, $importedUrl, $destinationUrl);
            } elseif ($importedUrl) {
                $this->output("\n  URLs match ({$destinationUrl}) — skipping URL search-replace\n");
            } else {
                $this->output("\n  ⚠ Could not detect imported URL — skipping URL search-replace\n");
            }
        }

        // 5. wp search-replace: swap source wordpress_path to destination path (if they differ)
        if ($sqlAdapter === 'wpcli' && $from->wordpress_path && $to->wordpress_path) {
            $fromPath = rtrim($from->wordpress_path, '/');
            $toPath = rtrim($to->wordpress_path, '/');

            if ($fromPath !== $toPath) {
                $this->output("\n  Running wp search-replace (Path)\n");
                $this->output("  {$fromPath} → {$toPath}\n\n");
                $this->runSearchReplace($to, $fromPath, $toPath);
            }
        }
    }

    private function backupDatabase(Environment $env, string $sqlAdapter, string $backupBasename): void
    {
        $backupFile = rtrim($env->wordpress_path, '/').'/wp-content/'.$backupBasename.'.sql';

        $this->output("  Backing up {$env->name}...\n");
        $this->output("  → wp-content/{$backupBasename}.sql.gz\n\n");

        $backupCmd = $this->buildBackupCommand($env, $sqlAdapter, $backupFile);

        try {
            if ($env->is_local) {
                $this->runProcess(['bash', '-c', $backupCmd]);
            } else {
                $sshCmd = $this->buildSshCommand($env);
                $this->runProcess(['bash', '-c', "{$sshCmd} ".escapeshellarg($backupCmd)]);
            }

            $this->output("  ✓ Backup saved: wp-content/{$backupBasename}.sql.gz\n\n");
        } catch (\Throwable $e) {
            $this->output("  ⚠ Backup failed (continuing): {$e->getMessage()}\n\n");
        }
    }

    private function buildBackupCommand(Environment $env, string $sqlAdapter, string $backupFile): string
    {
        if ($sqlAdapter === 'wpcli') {
            $exportCmd = sprintf(
                'wp db export %s --path=%s --allow-root',
                escapeshellarg($backupFile),
                escapeshellarg($env->wordpress_path)
            );
        } else {
            $args = sprintf(
                '-u %s -h %s -P %d',
                escapeshellarg($env->db_user),
                escapeshellarg($env->db_host),
                $env->db_port
            );

            if ($env->db_password) {
                $args .= sprintf(' -p%s', escapeshellarg($env->db_password));
            }

            $exportCmd = sprintf(
                'mysqldump %s %s %s > %s',
                $args,
                $env->mysqldump_options ?? '',
                escapeshellarg($env->db_name),
                escapeshellarg($backupFile)
            );
        }

        return $exportCmd.' && gzip -9 -f '.escapeshellarg($backupFile);
    }

    private function getWordPressSiteUrl(Environment $env): ?string
    {
        if ($env->site->sql_adapter !== 'wpcli') {
            return null;
        }

        if ($env->is_local) {
            $process = new Process(
                ['wp', 'option', 'get', 'siteurl', '--path='.$env->wordpress_path, '--skip-plugins', '--skip-themes', '--allow-root'],
                timeout: 30
            );
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        }

        $sshCmd = $this->buildSshCommand($env);
        $wpCmd = 'wp option get siteurl --path='.escapeshellarg($env->wordpress_path).' --skip-plugins --skip-themes --allow-root';
        $process = new Process(['bash', '-c', "{$sshCmd} ".escapeshellarg($wpCmd)], timeout: 30);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    private function runSearchReplace(Environment $env, string $old, string $new): void
    {
        if ($env->is_local) {
            $this->runProcess([
                'wp', 'search-replace', $old, $new,
                '--path='.$env->wordpress_path,
                '--skip-columns=guid',
                '--skip-plugins',
                '--skip-themes',
                '--all-tables',
                '--precise',
                '--allow-root',
            ]);

            return;
        }

        $sshCmd = $this->buildSshCommand($env);
        $wpCmd = sprintf(
            'wp search-replace %s %s %s --skip-columns=guid --skip-plugins --skip-themes --all-tables --precise --allow-root',
            escapeshellarg($old),
            escapeshellarg($new),
            escapeshellarg('--path='.$env->wordpress_path),
        );
        $this->runProcess(['bash', '-c', "{$sshCmd} ".escapeshellarg($wpCmd)]);
    }

    private function buildDumpCommand(Environment $env, string $adapter): string
    {
        if ($adapter === 'wpcli') {
            return sprintf(
                'wp db export - --path=%s --allow-root',
                escapeshellarg($env->wordpress_path)
            );
        }

        // mysqldump fallback
        $args = sprintf(
            '-u %s -h %s -P %d',
            escapeshellarg($env->db_user),
            escapeshellarg($env->db_host),
            $env->db_port
        );

        if ($env->db_password) {
            $args .= sprintf(' -p%s', escapeshellarg($env->db_password));
        }

        $extra = $env->mysqldump_options ?? '';

        return "mysqldump {$args} {$extra} ".escapeshellarg($env->db_name);
    }

    private function buildImportCommand(Environment $env, string $adapter): string
    {
        if ($adapter === 'wpcli') {
            return sprintf(
                'wp db import - --path=%s --allow-root',
                escapeshellarg($env->wordpress_path)
            );
        }

        $args = sprintf(
            '-u %s -h %s -P %d',
            escapeshellarg($env->db_user),
            escapeshellarg($env->db_host),
            $env->db_port
        );

        if ($env->db_password) {
            $args .= sprintf(' -p%s', escapeshellarg($env->db_password));
        }

        return "mysql {$args} ".escapeshellarg($env->db_name);
    }

    private function syncFiles(Environment $from, Environment $to, string $direction): void
    {
        $sourcePath = rtrim($from->wordpress_path, '/').'/wp-content/';
        $targetPath = rtrim($to->wordpress_path, '/').'/wp-content/';

        if (! $from->is_local) {
            $sourcePath = "{$from->ssh_user}@{$from->ssh_host}:{$sourcePath}";
        }

        if (! $to->is_local) {
            $targetPath = "{$to->ssh_user}@{$to->ssh_host}:{$targetPath}";
        }

        $remoteEnv = $from->is_local ? $to : $from;
        $this->runRsync($sourcePath, $targetPath, $remoteEnv, $from, $to);
    }

    private function syncWpContentDirectory(Environment $from, Environment $to, string $directory): void
    {
        $sourcePath = rtrim($from->wordpress_path, '/').'/wp-content/'.$directory.'/';
        $targetPath = rtrim($to->wordpress_path, '/').'/wp-content/'.$directory.'/';

        if (! $from->is_local) {
            $sourcePath = "{$from->ssh_user}@{$from->ssh_host}:{$sourcePath}";
        }

        if (! $to->is_local) {
            $targetPath = "{$to->ssh_user}@{$to->ssh_host}:{$targetPath}";
        }

        $remoteEnv = $from->is_local ? $to : $from;
        $this->runRsync($sourcePath, $targetPath, $remoteEnv, $from, $to);
    }

    private function syncCustomPath(Environment $from, Environment $to, string $path): void
    {
        $cleanPath = trim($path, '/');
        $sourcePath = rtrim($from->wordpress_path, '/').'/'.$cleanPath;
        $parentDir = dirname($cleanPath);
        $targetPath = $parentDir === '.'
            ? rtrim($to->wordpress_path, '/').'/'
            : rtrim($to->wordpress_path, '/').'/'.$parentDir.'/';

        if (! $from->is_local) {
            $sourcePath = "{$from->ssh_user}@{$from->ssh_host}:{$sourcePath}";
        }

        if (! $to->is_local) {
            $targetPath = "{$to->ssh_user}@{$to->ssh_host}:{$targetPath}";
        }

        $remoteEnv = $from->is_local ? $to : $from;
        $this->runRsync($sourcePath, $targetPath, $remoteEnv, $from, $to);
    }

    private function syncCore(Environment $from, Environment $to, string $direction): void
    {
        $corePatterns = ['wp-admin/', 'wp-includes/', 'wp-*.php'];

        foreach ($corePatterns as $pattern) {
            $sourcePath = rtrim($from->wordpress_path, '/').'/'.$pattern;
            $targetPath = rtrim($to->wordpress_path, '/').'/';

            if (! $from->is_local) {
                $sourcePath = "{$from->ssh_user}@{$from->ssh_host}:{$sourcePath}";
            }

            if (! $to->is_local) {
                $targetPath = "{$to->ssh_user}@{$to->ssh_host}:{$targetPath}";
            }

            $remoteEnv = $from->is_local ? $to : $from;
            $this->runRsync($sourcePath, $targetPath, $remoteEnv, $from, $to);
        }
    }

    private function runRsync(string $source, string $target, Environment $remoteEnv, Environment $from, Environment $to): void
    {
        $sshOptions = $this->buildSshOptions($remoteEnv);
        $excludes = $this->buildExcludes($from, $to);
        $rsyncOptions = $from->rsync_options ?? $to->rsync_options ?? '--verbose --itemize-changes --no-perms --no-owner --no-group';

        $cmd = ['rsync', '-avz'];

        if ($rsyncOptions) {
            foreach (explode(' ', $rsyncOptions) as $opt) {
                if ($opt !== '') {
                    $cmd[] = $opt;
                }
            }
        }

        $cmd[] = '-e';
        $cmd[] = "ssh {$sshOptions}";

        foreach ($excludes as $exclude) {
            $cmd[] = '--exclude='.$exclude;
        }

        $cmd[] = $source;
        $cmd[] = $target;

        $this->runProcess($cmd);
    }

    /**
     * Test SSH connectivity to a remote environment.
     *
     * @return array{success: bool, output: string}
     */
    public function testConnection(Environment $env): array
    {
        $options = $this->buildSshOptions($env);
        $command = ['ssh', ...$this->splitSshOptions($options), "{$env->ssh_user}@{$env->ssh_host}", 'echo SITESYNC_OK'];

        $process = new Process($command, timeout: 15);
        $process->run();

        $output = trim($process->getOutput().$process->getErrorOutput());

        return [
            'success' => $process->isSuccessful() && str_contains($process->getOutput(), 'SITESYNC_OK'),
            'output' => $output ?: 'No output received.',
        ];
    }

    /**
     * Split an SSH options string into an array of individual arguments.
     *
     * @return string[]
     */
    private function splitSshOptions(string $options): array
    {
        return array_values(array_filter(explode(' ', $options)));
    }

    private function buildSshCommand(Environment $env): string
    {
        $options = $this->buildSshOptions($env);

        return "ssh {$options} {$env->ssh_user}@{$env->ssh_host}";
    }

    private function buildSshOptions(Environment $env): string
    {
        $options = ['-o StrictHostKeyChecking=no', '-o BatchMode=yes'];

        if ($env->ssh_port && $env->ssh_port != 22) {
            $options[] = "-p {$env->ssh_port}";
        }

        $keyFile = $this->resolveKeyFile($env);
        if ($keyFile) {
            $options[] = "-i {$keyFile}";
        }

        return implode(' ', $options);
    }

    private function resolveKeyFile(Environment $env): ?string
    {
        if (! $env->sshKey) {
            return null;
        }

        $key = $env->sshKey;

        if ($key->type === 'file_path') {
            return $key->value;
        }

        // Write string key to a temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'sitesync_key_');
        file_put_contents($tmpFile, $key->value);
        chmod($tmpFile, 0600);

        // Register cleanup
        register_shutdown_function(fn () => @unlink($tmpFile));

        return $tmpFile;
    }

    private function buildExcludes(Environment ...$envs): array
    {
        $excludes = $this->defaultExcludes;

        foreach ($envs as $env) {
            if (! empty($env->exclude)) {
                $excludes = array_merge($excludes, $env->exclude);
            }
        }

        return array_unique($excludes);
    }

    private function runProcess(array $command): void
    {
        $process = new Process($command, timeout: 3600);

        $process->run(function (string $type, string $buffer): void {
            $this->output($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "Command failed (exit code {$process->getExitCode()}): ".$process->getErrorOutput()
            );
        }
    }

    private function output(string $text): void
    {
        echo $text;

        $this->log?->appendOutput($text);
    }
}
