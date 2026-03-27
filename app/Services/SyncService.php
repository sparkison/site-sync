<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\SyncLog;
use Symfony\Component\Process\Process;

class SyncService
{
    private ?SyncLog $log = null;

    private ?Process $currentProcess = null;

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

        // run any configured hooks for this operation
        if ($direction === 'push') {
            $this->runHooks($from, 'before_push_source');
            $this->runHooks($to, 'before_push_target');
        } else {
            $this->runHooks($from, 'before_pull_source');
            $this->runHooks($to, 'before_pull_target');
        }

        // Expand legacy 'files' to granular wp-content directories
        if (in_array('files', $scope)) {
            $scope = array_filter($scope, fn (string $s): bool => $s !== 'files');
            $scope = array_merge(array_values($scope), ['themes', 'plugins', 'uploads']);
        }

        $scope = array_unique($scope);

        // Ensure the destination WordPress directory exists before any file or DB operations.
        // This is a no-op for established environments and a one-time mkdir for new ones.
        $this->ensureWordPressDirectoryExists($to);

        // Ensure wp-content exists on the destination when any wp-content directories are being synced.
        // Rsync can create the final subdirectory (themes/, plugins/, etc.) but only if the parent exists.
        $wpContentScopes = ['themes', 'plugins', 'mu-plugins', 'uploads'];
        if (! empty(array_intersect($wpContentScopes, $scope))) {
            $this->ensureWpContentDirectoryExists($to);
        }

        // Core must run before the database so that wp-config.php exists on the destination
        // before `wp db import` is executed — this is essential when pushing to a new environment.
        if (in_array('core', $scope)) {
            $this->throwIfCancelled();
            $this->output("=== Syncing WordPress Core ===\n");
            $this->syncCore($from, $to, $direction);
        }

        if (in_array('db', $scope)) {
            $this->throwIfCancelled();
            $this->output("=== Syncing Database ===\n");
            $this->syncDatabase($from, $to, $direction);
        }

        foreach (['themes', 'plugins', 'mu-plugins', 'uploads'] as $wpContentDir) {
            if (in_array($wpContentDir, $scope)) {
                $this->throwIfCancelled();
                $this->output("=== Syncing wp-content/{$wpContentDir}/ ===\n");
                $this->syncWpContentDirectory($from, $to, $wpContentDir);
            }
        }

        foreach (array_diff($scope, self::KNOWN_SCOPES) as $customPath) {
            $this->output("=== Syncing custom path: {$customPath} ===\n");
            $this->syncCustomPath($from, $to, $customPath);
        }

        // hooks after the sync
        if ($direction === 'push') {
            $this->runHooks($from, 'after_push_source');
            $this->runHooks($to, 'after_push_target');
        } else {
            $this->runHooks($from, 'after_pull_source');
            $this->runHooks($to, 'after_pull_target');
        }
    }

    private function syncDatabase(Environment $from, Environment $to, string $direction): void
    {
        $sqlAdapter = $from->site->sql_adapter;
        $timestamp = time();
        $backupBasename = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $to->name)).'-backup-'.$timestamp;

        // 0. Ensure wp-config.php exists before any WP-CLI commands run.
        //    wp-config.php is typically excluded from file sync, so we auto-create it
        //    from stored credentials when pushing to a new environment.
        if ($sqlAdapter === 'wpcli') {
            $this->ensureWpConfigExists($to);
        }

        // 1. Backup destination before overwriting
        $this->backupDatabase($to, $sqlAdapter, $backupBasename);

        // 2. Determine URLs for search-replace before touching the destination database.
        //    We read the SOURCE URL from $from now (before import) so we always have the
        //    correct "imported" URL, even when the local DB was last restored from the remote.
        $destinationUrl = null;
        $sourceUrl = null;

        if ($sqlAdapter === 'wpcli') {
            if ($to->vhost) {
                $destinationUrl = rtrim($to->vhost, '/');
                $this->output("  Destination URL (from vhost): {$destinationUrl}\n");
            } else {
                $this->output("  Detecting {$to->name} destination URL...\n");
                $destinationUrl = $this->getWordPressSiteUrl($to);
                $this->output($destinationUrl
                    ? "  → Destination URL: {$destinationUrl}\n  ⚠ Tip: set the Vhost on this environment for reliable search-replace.\n"
                    : "  → Could not detect destination URL — URL search-replace will be skipped\n"
                );
            }

            $this->output("  Detecting {$from->name} source URL...\n");
            $sourceUrl = $this->getWordPressSiteUrl($from);
            $this->output($sourceUrl
                ? "  → Source URL: {$sourceUrl}\n\n"
                : "  → Could not detect source URL — URL search-replace will be skipped\n\n"
            );
        }

        // 3. Dump source and import to destination
        $this->output("  Dumping {$from->name} and importing into {$to->name}...\n\n");

        if ($from->is_local && $to->is_local) {
            // Both local — simple pipe is fine
            $dumpCmd = $this->buildDumpCommand($from, $sqlAdapter);
            $importCmd = $this->buildImportCommand($to, $sqlAdapter);
            $this->runProcess(['bash', '-c', "{$dumpCmd} | {$importCmd}"]);
        } elseif ($from->is_local && ! $to->is_local) {
            // Push: local → remote
            // Piping a local dump into a remote `wp db import -` over SSH stdin is unreliable
            // on many shared hosts (stdin may not be faithfully forwarded). We instead dump to
            // a local temp file, stream it to a remote temp file via SSH, then import on remote.
            if ($sqlAdapter === 'wpcli') {
                $this->importViaRemoteTempFile($from, $to);
            } else {
                $dumpCmd = $this->buildDumpCommand($from, $sqlAdapter);
                $importCmd = $this->buildImportCommand($to, $sqlAdapter);
                $sshCmd = $this->buildSshCommand($to);
                $this->runProcess(['bash', '-c', "set -o pipefail; {$dumpCmd} | {$sshCmd} ".escapeshellarg($importCmd)]);
            }
        } elseif (! $from->is_local && $to->is_local) {
            // Pull: remote → local
            if ($sqlAdapter === 'wpcli') {
                $this->exportViaRemoteTempFile($from, $to);
            } else {
                $dumpCmd = $this->buildDumpCommand($from, $sqlAdapter);
                $importCmd = $this->buildImportCommand($to, $sqlAdapter);
                $sshCmd = $this->buildSshCommand($from);
                $this->runProcess(['bash', '-c', "set -o pipefail; {$sshCmd} ".escapeshellarg($dumpCmd)." | {$importCmd}"]);
            }
        } else {
            throw new \RuntimeException('Remote-to-remote database sync is not supported. Use a local environment as intermediary.');
        }

        // 4. wp search-replace: swap the source URL to the destination URL.
        //    We use $sourceUrl (captured before the import) so this works correctly
        //    even when the local DB already held the remote URL from a previous pull.
        if ($destinationUrl && $sourceUrl && $sqlAdapter === 'wpcli') {
            if ($sourceUrl !== $destinationUrl) {
                $this->output("\n  Running wp search-replace (URL)\n");
                $this->output("  {$sourceUrl} → {$destinationUrl}\n\n");
                $this->runSearchReplace($to, $sourceUrl, $destinationUrl);

                // Gutenberg stores some asset URLs (e.g. custom fonts in wp_global_styles)
                // without the scheme, or inside doubly-encoded JSON, so a schema-based replace
                // misses them. A second pass using only the hostname catches these cases.
                $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
                $destinationHost = parse_url($destinationUrl, PHP_URL_HOST);

                if ($sourceHost && $destinationHost && $sourceHost !== $destinationHost) {
                    $this->output("\n  Running wp search-replace (Domain only, for Gutenberg assets)\n");
                    $this->output("  {$sourceHost} → {$destinationHost}\n\n");
                    $this->runSearchReplace($to, $sourceHost, $destinationHost);
                }
            } else {
                $this->output("\n  URLs match ({$destinationUrl}) — skipping URL search-replace\n");
            }
        } elseif ($destinationUrl && $sqlAdapter === 'wpcli') {
            $this->output("\n  ⚠ Could not detect source URL — skipping URL search-replace\n");
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
        $gzip = $env->is_local ? $this->binary('gzip') : 'gzip';

        if ($sqlAdapter === 'wpcli') {
            $wp = $env->is_local ? $this->binary('wp') : 'wp';

            $exportCmd = sprintf(
                '%s db export %s --path=%s --allow-root',
                $wp,
                escapeshellarg($backupFile),
                escapeshellarg($env->wordpress_path)
            );
        } else {
            $mysqldump = $env->is_local ? $this->binary('mysqldump') : 'mysqldump';

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
                '%s %s %s %s > %s',
                $mysqldump,
                $args,
                $env->mysqldump_options ?? '',
                escapeshellarg($env->db_name),
                escapeshellarg($backupFile)
            );
        }

        return $exportCmd.' && '.$gzip.' -9 -f '.escapeshellarg($backupFile);
    }

    /**
     * Ensure wp-content/ exists on the destination environment.
     * Rsync can create final subdirectories (themes/, plugins/, …) but only when the parent
     * directory already exists — this creates it when syncing to a brand-new install.
     */
    private function ensureWpContentDirectoryExists(Environment $env): void
    {
        $path = rtrim($env->wordpress_path, '/').'/wp-content';

        if ($env->is_local) {
            if (! is_dir($path)) {
                $this->output("  ⚠ wp-content not found locally — creating {$path}\n");
                mkdir($path, 0755, true);
            }

            return;
        }

        $ssh = $this->buildSshCommand($env);
        $mkdirProcess = new Process(
            ['bash', '-c', $ssh.' '.escapeshellarg('mkdir -p '.escapeshellarg($path))],
            timeout: 15
        );
        $mkdirProcess->run();

        if (! $mkdirProcess->isSuccessful()) {
            $this->output("  ⚠ Could not create wp-content on {$env->name} — rsync may fail\n");
        }
    }

    /**
     * Ensure the destination WordPress directory exists.
     * When pushing to a brand-new environment there may be nothing at the path yet.
     * For remote envs we create the directory via SSH mkdir; for local we use PHP.
     */
    private function ensureWordPressDirectoryExists(Environment $env): void
    {
        $path = rtrim($env->wordpress_path, '/');

        if ($env->is_local) {
            if (! is_dir($path)) {
                $this->output("  ⚠ WordPress directory not found locally — creating {$path}\n");
                mkdir($path, 0755, true);
            }

            return;
        }

        $ssh = $this->buildSshCommand($env);
        $checkCmd = 'test -d '.escapeshellarg($path).' && echo EXISTS || echo MISSING';
        $process = new Process(['bash', '-c', "{$ssh} ".escapeshellarg($checkCmd)], timeout: 15);
        $process->run();

        if (str_contains($process->getOutput(), 'MISSING')) {
            $this->output("  ⚠ WordPress directory not found on {$env->name} — creating {$path}\n");
            $mkdirProcess = new Process(['bash', '-c', $ssh.' '.escapeshellarg('mkdir -p '.escapeshellarg($path))], timeout: 15);
            $mkdirProcess->run();

            if (! $mkdirProcess->isSuccessful()) {
                throw new \RuntimeException("Could not create WordPress directory on {$env->name}: {$path}");
            }
        }
    }

    /**
     * Ensure the destination has a wp-config.php before any WP-CLI commands are run.
     *
     * Strategy 1 — wp config create  (requires WP-CLI + wp-config-sample.php on the remote).
     * Strategy 2 — generate a minimal wp-config.php locally from stored DB credentials
     *              and upload it via an SSH pipe, so no SCP/SFTP dependency is needed.
     */
    private function ensureWpConfigExists(Environment $env): void
    {
        if ($this->hasWpConfig($env)) {
            return;
        }

        $this->output("  ⚠ No wp-config.php found on {$env->name}.\n");

        if (! $env->db_name || ! $env->db_user) {
            $this->output("  ⚠ No DB credentials configured — database import may fail.\n\n");

            return;
        }

        $this->output("  Attempting to create wp-config.php from stored environment credentials...\n");

        if ($this->createWpConfigViaWpCli($env)) {
            $this->output("  ✓ wp-config.php created via wp-cli.\n\n");

            return;
        }

        // WP-CLI not available or failed — generate and upload a minimal config.
        $this->uploadGeneratedWpConfig($env);
        $this->output("  ✓ wp-config.php generated and uploaded.\n\n");
    }

    /**
     * Check whether wp-config.php already exists on the given environment.
     */
    private function hasWpConfig(Environment $env): bool
    {
        $path = rtrim($env->wordpress_path, '/').'/wp-config.php';

        if ($env->is_local) {
            return file_exists($path);
        }

        $ssh = $this->buildSshCommand($env);
        $checkCmd = 'test -f '.escapeshellarg($path).' && echo EXISTS || echo MISSING';
        $process = new Process(['bash', '-c', "{$ssh} ".escapeshellarg($checkCmd)], timeout: 15);
        $process->run();

        return str_contains($process->getOutput(), 'EXISTS');
    }

    /**
     * Attempt to create wp-config.php on the destination using `wp config create`.
     * This requires WP-CLI and wp-config-sample.php to already be present (they will
     * be if core was synced first).
     *
     * Returns true on success, false if WP-CLI is unavailable or the command fails.
     */
    private function createWpConfigViaWpCli(Environment $env): bool
    {
        $dbHost = $env->db_host ?? 'localhost';
        if ($env->db_port && $env->db_port != 3306) {
            $dbHost .= ':'.$env->db_port;
        }

        $args = [
            '--dbname='.$env->db_name,
            '--dbuser='.$env->db_user,
            '--dbhost='.$dbHost,
            '--dbprefix='.($env->db_prefix ?: 'wp_'),
            '--path='.$env->wordpress_path,
            '--skip-check',
            '--allow-root',
            '--force',
        ];

        if ($env->db_password) {
            $args[] = '--dbpass='.$env->db_password;
        }

        try {
            if ($env->is_local) {
                $this->runProcess(array_merge([$this->binary('wp'), 'config', 'create'], $args));
            } else {
                $wpCmd = 'wp config create '.implode(' ', array_map('escapeshellarg', $args));
                $ssh = $this->buildSshCommand($env);
                $this->runProcess(['bash', '-c', "{$ssh} ".escapeshellarg($wpCmd)]);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate a minimal but functional wp-config.php from the environment's stored DB
     * credentials and upload it. Used as a fallback when WP-CLI is not available.
     */
    private function uploadGeneratedWpConfig(Environment $env): void
    {
        $content = $this->buildMinimalWpConfigContent($env);
        $remotePath = rtrim($env->wordpress_path, '/').'/wp-config.php';

        if ($env->is_local) {
            file_put_contents($remotePath, $content);

            return;
        }

        // Write to a local temp file and stream it over SSH without needing SCP/SFTP.
        $tmpFile = tempnam(sys_get_temp_dir(), 'sitesync_wpcfg_');
        file_put_contents($tmpFile, $content);

        register_shutdown_function(fn () => @unlink($tmpFile));

        $ssh = $this->buildSshCommand($env);
        $receiveCmd = 'cat > '.escapeshellarg($remotePath);
        $this->runProcess(['bash', '-c', 'cat '.escapeshellarg($tmpFile)." | {$ssh} ".escapeshellarg($receiveCmd)]);
    }

    /**
     * Build the PHP content of a minimal wp-config.php using the environment's stored
     * DB credentials and fresh random authentication salts.
     */
    private function buildMinimalWpConfigContent(Environment $env): string
    {
        $dbHost = $env->db_host ?? 'localhost';
        if ($env->db_port && $env->db_port != 3306) {
            $dbHost .= ':'.$env->db_port;
        }

        $esc = fn (string $v): string => str_replace("'", "\\'", $v);
        $salt = fn (): string => bin2hex(random_bytes(32));
        $prefix = $esc($env->db_prefix ?: 'wp_');

        $saltKeys = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
        $saltLines = implode("\n", array_map(fn (string $k): string => "define( '{$k}', '{$salt()}' );", $saltKeys));

        return <<<PHP
        <?php
        define( 'DB_NAME',     '{$esc($env->db_name ?? '')}' );
        define( 'DB_USER',     '{$esc($env->db_user ?? '')}' );
        define( 'DB_PASSWORD', '{$esc($env->db_password ?? '')}' );
        define( 'DB_HOST',     '{$esc($dbHost)}' );
        define( 'DB_CHARSET',  'utf8mb4' );
        define( 'DB_COLLATE',  '' );

        {$saltLines}

        \$table_prefix = '{$prefix}';

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', __DIR__ . '/' );
        }

        require_once ABSPATH . 'wp-settings.php';
        PHP;
    }

    private function getWordPressSiteUrl(Environment $env): ?string
    {
        if ($env->site->sql_adapter !== 'wpcli') {
            return null;
        }

        if ($env->is_local) {
            $process = new Process(
                [$this->binary('wp'), 'option', 'get', 'siteurl', '--path='.$env->wordpress_path, '--skip-plugins', '--skip-themes', '--allow-root'],
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
                $this->binary('wp'), 'search-replace', $old, $new,
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
            $wp = $env->is_local ? $this->binary('wp') : 'wp';

            return sprintf('%s db export - --path=%s --allow-root', $wp, escapeshellarg($env->wordpress_path));
        }

        $mysqldump = $env->is_local ? $this->binary('mysqldump') : 'mysqldump';

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

        return "{$mysqldump} {$args} {$extra} ".escapeshellarg($env->db_name);
    }

    private function buildImportCommand(Environment $env, string $adapter): string
    {
        if ($adapter === 'wpcli') {
            $wp = $env->is_local ? $this->binary('wp') : 'wp';

            return sprintf('%s db import - --path=%s --allow-root', $wp, escapeshellarg($env->wordpress_path));
        }

        $mysql = $env->is_local ? $this->binary('mysql') : 'mysql';

        $args = sprintf(
            '-u %s -h %s -P %d',
            escapeshellarg($env->db_user),
            escapeshellarg($env->db_host),
            $env->db_port
        );

        if ($env->db_password) {
            $args .= sprintf(' -p%s', escapeshellarg($env->db_password));
        }

        return "{$mysql} {$args} ".escapeshellarg($env->db_name);
    }

    /**
     * Push DB: dump locally to a temp file, copy it to the remote via scp, import.
     *
     * Using scp (rather than SSH stdin piping) avoids hangs on shared hosts that
     * throttle or restrict SSH stdin forwarding for large payloads.
     */
    private function importViaRemoteTempFile(Environment $from, Environment $to): void
    {
        $wp = $this->binary('wp');
        $tmpLocal = sys_get_temp_dir().'/sitesync_dump_'.uniqid().'.sql';
        $tmpRemote = '/tmp/sitesync_import_'.uniqid().'.sql';

        register_shutdown_function(fn () => @unlink($tmpLocal));

        try {
            // 1. Dump locally to a temp file
            $this->runProcess([
                $wp, 'db', 'export', $tmpLocal,
                '--path='.$from->wordpress_path,
                '--allow-root',
            ]);

            if (! file_exists($tmpLocal) || filesize($tmpLocal) === 0) {
                throw new \RuntimeException('Local database dump produced an empty file — aborting import.');
            }

            // 2. Copy to the remote via scp — no SSH stdin pipe involved
            $scpOptions = $this->buildScpOptions($to);
            $this->runProcess(array_merge(
                ['scp'],
                $scpOptions,
                [$tmpLocal, "{$to->ssh_user}@{$to->ssh_host}:{$tmpRemote}"],
            ));

            // 3. Import from the remote temp file
            $sshCmd = $this->buildSshCommand($to);
            $remoteImportCmd = sprintf(
                'wp db import %s --path=%s --allow-root',
                escapeshellarg($tmpRemote),
                escapeshellarg($to->wordpress_path),
            );
            $this->runProcess(['bash', '-c', $sshCmd.' '.escapeshellarg($remoteImportCmd)]);
        } finally {
            // 4. Clean up remote temp file (best-effort)
            $sshCmd = $this->buildSshCommand($to);
            $cleanupProcess = new Process(['bash', '-c', $sshCmd.' '.escapeshellarg('rm -f '.escapeshellarg($tmpRemote))], timeout: 15);
            $cleanupProcess->run();
        }
    }

    /**
     * Pull DB: dump to a remote temp file, download it via scp, then import.
     *
     * Using scp (rather than SSH stdout piping) avoids bashrc/shell output contaminating
     * the stream and prevents hangs on shared hosts that restrict SSH I/O forwarding.
     */
    private function exportViaRemoteTempFile(Environment $from, Environment $to): void
    {
        $wp = $this->binary('wp');
        $tmpLocal = sys_get_temp_dir().'/sitesync_dump_'.uniqid().'.sql';
        $tmpRemote = '/tmp/sitesync_export_'.uniqid().'.sql';

        register_shutdown_function(fn () => @unlink($tmpLocal));

        $sshCmd = $this->buildSshCommand($from);

        try {
            // 1. Dump to a temp file on the remote
            $this->runProcess(['bash', '-c', $sshCmd.' '.escapeshellarg(sprintf(
                'wp db export %s --path=%s --allow-root',
                escapeshellarg($tmpRemote),
                escapeshellarg($from->wordpress_path),
            ))]);

            // 2. Download the remote temp file via scp — no SSH stdout pipe involved
            $scpOptions = $this->buildScpOptions($from);
            $this->runProcess(array_merge(
                ['scp'],
                $scpOptions,
                ["{$from->ssh_user}@{$from->ssh_host}:{$tmpRemote}", $tmpLocal],
            ));

            if (! file_exists($tmpLocal) || filesize($tmpLocal) === 0) {
                throw new \RuntimeException('Remote database dump produced an empty file — aborting import.');
            }

            // 3. Import locally
            $this->runProcess([
                $wp, 'db', 'import', $tmpLocal,
                '--path='.$to->wordpress_path,
                '--allow-root',
            ]);
        } finally {
            // 4. Clean up remote temp file (best-effort)
            $cleanupProcess = new Process(['bash', '-c', $sshCmd.' '.escapeshellarg('rm -f '.escapeshellarg($tmpRemote))], timeout: 15);
            $cleanupProcess->run();
        }
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
        // Globs (wp-*.php, *.html) must target the root dir so rsync places matched files there.
        // Explicit files (index.php, xmlrpc.php, …) target themselves directly.
        $corePatterns = ['wp-admin/', 'wp-includes/', 'index.php', 'xmlrpc.php', 'license.txt', 'wp-*.php', '*.html'];

        foreach ($corePatterns as $pattern) {
            $sourcePath = rtrim($from->wordpress_path, '/').'/'.$pattern;
            $isGlob = str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '[');
            if ($isGlob) {
                $targetPath = rtrim($to->wordpress_path, '/').'/';
            } else {
                $targetPath = rtrim($to->wordpress_path, '/').'/'.$pattern;
            }

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

        // When the source path contains glob characters (e.g. wp-*.php) we must run through
        // a shell so that local globs are expanded before rsync sees the argument.
        // For remote sources (user@host:/path/glob) the source is quoted so the local shell
        // leaves it untouched and rsync handles expansion on the remote side.
        $hasGlob = str_contains($source, '*') || str_contains($source, '?') || str_contains($source, '[');

        if ($hasGlob) {
            $eFlag = "{$this->binary('ssh')} {$sshOptions}";

            $parts = [escapeshellarg($this->binary('rsync')), '-avz'];

            if ($rsyncOptions) {
                foreach (array_filter(explode(' ', $rsyncOptions)) as $opt) {
                    $parts[] = $opt;
                }
            }

            $parts[] = '-e';
            $parts[] = escapeshellarg($eFlag);

            foreach ($excludes as $exclude) {
                $parts[] = '--exclude='.$exclude;
            }

            // Local source globs must NOT be quoted so the shell expands them.
            // Remote source paths (containing a colon) are quoted so the local shell
            // leaves them untouched and rsync passes them to the remote side.
            $isRemoteSource = str_contains($source, ':');
            $sourcePart = $isRemoteSource ? escapeshellarg($source) : $source;

            $shellCmd = implode(' ', $parts).' '.$sourcePart.' '.escapeshellarg($target);
            $this->runProcess(['bash', '-c', $shellCmd]);

            return;
        }

        $cmd = [$this->binary('rsync'), '-avz'];

        if ($rsyncOptions) {
            foreach (explode(' ', $rsyncOptions) as $opt) {
                if ($opt !== '') {
                    $cmd[] = $opt;
                }
            }
        }

        $cmd[] = '-e';
        $cmd[] = "{$this->binary('ssh')} {$sshOptions}";

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
        $command = [$this->binary('ssh'), ...$this->splitSshOptions($options), "{$env->ssh_user}@{$env->ssh_host}", 'echo SITESYNC_OK'];

        $process = new Process($command, timeout: 15);
        $process->run();

        $output = trim($process->getOutput().$process->getErrorOutput());

        return [
            'success' => $process->isSuccessful() && str_contains($process->getOutput(), 'SITESYNC_OK'),
            'output' => $output ?: 'No output received.',
        ];
    }

    /**
     * Execute arbitrary commands stored as hooks for an environment.
     *
     * @param  string  $hook  array key such as "before_push_source"
     */
    private function runHooks(Environment $env, string $hook): void
    {
        $entries = $env->sync_hooks[$hook] ?? [];

        foreach ((array) $entries as $entry) {
            $cmd = is_array($entry) ? ($entry['command'] ?? '') : (string) $entry;
            if ($cmd === '') {
                continue;
            }

            $this->output("→ running {$hook} on {$env->name}: {$cmd}\n");

            if ($env->is_local) {
                $this->runProcess(['bash', '-lc', $cmd]);
            } else {
                $ssh = $this->buildSshCommand($env);
                $this->runProcess(['bash', '-c', "{$ssh} ".escapeshellarg($cmd)]);
            }
        }
    }

    /**
     * Allows ad hoc execution of a command on the given environment.
     *
     * @return array{success: bool, output: string}
     */
    public function runCommand(Environment $env, string $command): array
    {
        if ($env->is_local) {
            $process = new Process(['bash', '-lc', $command], timeout: 3600);
        } else {
            $ssh = $this->buildSshCommand($env);
            $process = new Process(['bash', '-c', "{$ssh} ".escapeshellarg($command)], timeout: 3600);
        }

        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => trim($process->getOutput().$process->getErrorOutput()),
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

    /**
     * Build scp option array from an environment's SSH connection settings.
     *
     * scp uses -P (uppercase) for port, unlike ssh's -p (lowercase).
     *
     * @return string[]
     */
    private function buildScpOptions(Environment $env): array
    {
        $options = ['-o', 'StrictHostKeyChecking=no', '-o', 'BatchMode=yes'];

        if ($env->ssh_port && $env->ssh_port != 22) {
            $options[] = '-P';
            $options[] = (string) $env->ssh_port;
        }

        $keyFile = $this->resolveKeyFile($env);
        if ($keyFile) {
            $options[] = '-i';
            $options[] = $keyFile;
        }

        return $options;
    }

    private function buildSshCommand(Environment $env): string
    {
        $options = $this->buildSshOptions($env);

        return "{$this->binary('ssh')} {$options} {$env->ssh_user}@{$env->ssh_host}";
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
        $excludes = app(AppSettings::class)->get('default_ignores', []);

        foreach ($envs as $env) {
            if (! empty($env->exclude)) {
                $excludes = array_merge($excludes, $env->exclude);
            }
        }

        return array_unique($excludes);
    }

    private function binary(string $name): string
    {
        $paths = app(AppSettings::class)->get('custom_paths', []);

        return ($paths[$name] ?? null) ?: $name;
    }

    private function runProcess(array $command): void
    {
        $process = new Process($command, timeout: 3600);
        $this->currentProcess = $process;

        try {
            $process->start(function (string $type, string $buffer): void {
                $this->output($buffer);
            });

            while ($process->isRunning()) {
                $process->checkTimeout();

                if ($this->log && $this->log->fresh()->isCancelled()) {
                    $process->stop(3);
                    throw new \RuntimeException('Sync cancelled by user.');
                }

                usleep(500_000);
            }

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(
                    "Command failed (exit code {$process->getExitCode()}): ".$process->getErrorOutput()
                );
            }
        } finally {
            $this->currentProcess = null;
        }
    }

    private function throwIfCancelled(): void
    {
        if ($this->log && $this->log->fresh()->isCancelled()) {
            throw new \RuntimeException('Sync cancelled by user.');
        }
    }

    private function output(string $text): void
    {
        $text = $this->filterOutput($text);

        if ($text === '') {
            return;
        }

        echo $text;

        $this->log?->appendOutput($text);
    }

    private function filterOutput(string $text): string
    {
        // Strip verbose WP-CLI "Skipping an inconvertible serialized object" warnings —
        // these are harmless but produce enormous lines that freeze the terminal.
        return preg_replace('/Warning: Skipping an inconvertible serialized object:[^\n]*/m', '', $text) ?? $text;
    }
}
