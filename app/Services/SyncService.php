<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\SyncLog;
use Symfony\Component\Process\Process;

class SyncService
{
    private ?SyncLog $log = null;

    private array $defaultExcludes = [
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

        // Capture destination's current siteurl before overwriting — used for search-replace after import
        $destinationUrl = $this->getWordPressSiteUrl($to);

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

        // After import the destination DB now contains the source's URLs — swap them back
        if ($destinationUrl && $sqlAdapter === 'wpcli') {
            $importedUrl = $this->getWordPressSiteUrl($to);

            if ($importedUrl && $importedUrl !== $destinationUrl) {
                $this->output("\n=== Running wp search-replace ===\n");
                $this->output("  {$importedUrl} → {$destinationUrl}\n\n");
                $this->runSearchReplace($to, $importedUrl, $destinationUrl);
            }
        }
    }

    private function getWordPressSiteUrl(Environment $env): ?string
    {
        if ($env->site->sql_adapter !== 'wpcli') {
            return null;
        }

        if ($env->is_local) {
            $process = new Process(
                ['wp', 'option', 'get', 'siteurl', '--path='.$env->wordpress_path, '--allow-root'],
                timeout: 30
            );
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        }

        $sshCmd = $this->buildSshCommand($env);
        $wpCmd = 'wp option get siteurl --path='.escapeshellarg($env->wordpress_path).' --allow-root';
        $process = new Process(['bash', '-c', "{$sshCmd} ".escapeshellarg($wpCmd)], timeout: 30);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    private function runSearchReplace(Environment $env, string $oldUrl, string $newUrl): void
    {
        if ($env->is_local) {
            $this->runProcess([
                'wp', 'search-replace', $oldUrl, $newUrl,
                '--path='.$env->wordpress_path,
                '--all-tables',
                '--precise',
                '--allow-root',
            ]);

            return;
        }

        $sshCmd = $this->buildSshCommand($env);
        $wpCmd = sprintf(
            'wp search-replace %s %s %s --all-tables --precise --allow-root',
            escapeshellarg($oldUrl),
            escapeshellarg($newUrl),
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
