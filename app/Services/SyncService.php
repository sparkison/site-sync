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

    private function runSync(Environment $from, Environment $to, string $direction, array $scope): void
    {
        if (in_array('all', $scope)) {
            $scope = ['db', 'core', 'files'];
        }

        if (in_array('db', $scope)) {
            $this->output("=== Syncing Database ===\n");
            $this->syncDatabase($from, $to, $direction);
        }

        if (in_array('core', $scope)) {
            $this->output("=== Syncing WordPress Core ===\n");
            $this->syncCore($from, $to, $direction);
        }

        if (in_array('files', $scope)) {
            $this->output("=== Syncing WordPress Files (themes/plugins/uploads) ===\n");
            $this->syncFiles($from, $to, $direction);
        }
    }

    private function syncDatabase(Environment $from, Environment $to, string $direction): void
    {
        $fromEnv = $from;
        $toEnv = $to;

        $sqlAdapter = $from->site->sql_adapter;

        // Build dump command (runs on $from environment)
        $dumpCmd = $this->buildDumpCommand($fromEnv, $sqlAdapter);
        // Build import command (runs on $to environment)
        $importCmd = $this->buildImportCommand($toEnv, $sqlAdapter);

        if ($fromEnv->is_local && $toEnv->is_local) {
            // Both local: pipe locally
            $this->runProcess(array_merge(['bash', '-c', "{$dumpCmd} | {$importCmd}"]));
        } elseif ($fromEnv->is_local && ! $toEnv->is_local) {
            // Dump locally, import remotely via SSH
            $sshCmd = $this->buildSshCommand($toEnv);
            $cmd = "{$dumpCmd} | {$sshCmd} '{$importCmd}'";
            $this->runProcess(['bash', '-c', $cmd]);
        } elseif (! $fromEnv->is_local && $toEnv->is_local) {
            // Dump remotely via SSH, import locally
            $sshCmd = $this->buildSshCommand($fromEnv);
            $cmd = "{$sshCmd} '{$dumpCmd}' | {$importCmd}";
            $this->runProcess(['bash', '-c', $cmd]);
        } else {
            // Remote to remote: not supported directly, use local as intermediary
            throw new \RuntimeException('Remote-to-remote database sync is not supported. Use a local environment as intermediary.');
        }
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

        return "mysqldump {$args} {$extra} " . escapeshellarg($env->db_name);
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

        return "mysql {$args} " . escapeshellarg($env->db_name);
    }

    private function syncFiles(Environment $from, Environment $to, string $direction): void
    {
        $sourcePath = rtrim($from->wordpress_path, '/') . '/wp-content/';
        $targetPath = rtrim($to->wordpress_path, '/') . '/wp-content/';

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
            $sourcePath = rtrim($from->wordpress_path, '/') . '/' . $pattern;
            $targetPath = rtrim($to->wordpress_path, '/') . '/';

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
            $cmd[] = '--exclude=' . $exclude;
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
                "Command failed (exit code {$process->getExitCode()}): " . $process->getErrorOutput()
            );
        }
    }

    private function output(string $text): void
    {
        echo $text;

        $this->log?->appendOutput($text);
    }
}
