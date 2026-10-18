<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy;

use Dhirimetaa\ProdDeploy\Support\Output;
use FilesystemIterator;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class DeployKernel
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public function relativePath(string $absolutePath, string $root): string
    {
        $absolutePath = $this->normalizePath($absolutePath);
        $root = rtrim($this->normalizePath($root), '/');

        if (! str_starts_with($absolutePath, $root)) {
            return ltrim($absolutePath, '/');
        }

        return ltrim(substr($absolutePath, strlen($root)), '/');
    }

    /** @return array<string, string> */
    public function loadEnvFile(string $path): array
    {
        if (! is_file($path)) {
            Output::fail("Missing config file: {$path}");
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }

    /** @return array<string, string> */
    public function loadDeployEnv(): array
    {
        $values = $this->loadEnvFile($this->app->configDir().'/deploy.env');

        foreach (['PROD_SSH_HOST', 'PROD_SSH_USER', 'PROD_SSH_PORT', 'PROD_REMOTE_PATH'] as $required) {
            if (empty($values[$required])) {
                Output::fail("deploy/deploy.env is missing {$required}. Run prod-deploy init and edit that file.");
            }
        }

        return $values;
    }

    /** @return list<string> */
    public function loadExcludePatterns(string $filename): array
    {
        $path = $this->app->excludeFilePath($filename);
        if (! is_file($path)) {
            return [];
        }

        $patterns = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
                continue;
            }
            $patterns[] = $line;
        }

        return $patterns;
    }

    /** @param list<string> $patterns */
    public function pathMatchesExclude(string $relativePath, array $patterns): bool
    {
        $relativePath = $this->normalizePath($relativePath);

        foreach ($patterns as $pattern) {
            $pattern = $this->normalizePath($pattern);
            if (str_starts_with($pattern, '/')) {
                $pattern = ltrim($pattern, '/');
            }
            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '/')) {
                $dirPattern = rtrim($pattern, '/');
                if ($relativePath === $dirPattern || str_starts_with($relativePath, $dirPattern.'/')) {
                    return true;
                }
                if (fnmatch($pattern, $relativePath, FNM_CASEFOLD)) {
                    return true;
                }
                continue;
            }

            if (fnmatch($pattern, $relativePath, FNM_CASEFOLD)
                || fnmatch($pattern, basename($relativePath), FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $excludePatterns
     * @return list<string>
     */
    public function collectFiles(string $root, array $excludePatterns, string $excludePathPrefix = ''): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $this->walk($root, '', $excludePatterns, $excludePathPrefix, $files);
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $excludePatterns
     * @param  list<string>  $files
     */
    private function walk(
        string $directory,
        string $relativePrefix,
        array $excludePatterns,
        string $excludePathPrefix,
        array &$files
    ): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = $relativePrefix === '' ? $entry : $relativePrefix.'/'.$entry;
            $excludeKey = $excludePathPrefix !== ''
                ? $this->normalizePath($excludePathPrefix.'/'.$relative)
                : $relative;
            $full = $directory.'/'.$entry;

            if (is_dir($full)) {
                if ($this->pathMatchesExclude($excludeKey, $excludePatterns)
                    || $this->pathMatchesExclude($excludeKey.'/', $excludePatterns)) {
                    continue;
                }
                $this->walk($full, $relative, $excludePatterns, $excludePathPrefix, $files);
                continue;
            }

            if (! is_file($full) || $this->pathMatchesExclude($excludeKey, $excludePatterns)) {
                continue;
            }

            $files[] = $this->normalizePath($relative);
        }
    }

    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            Output::fail("Could not create directory: {$path}");
        }
    }

    public function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0666);
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    public function copyFile(string $source, string $destination): void
    {
        $this->ensureDirectory(dirname($destination));
        if (! copy($source, $destination)) {
            Output::fail("Failed to copy {$source} to {$destination}");
        }
    }

    /** @param list<string> $command */
    public function runProcess(array $command, ?string $cwd = null): int
    {
        $cmd = implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
        Output::info('running '.$cmd.($cwd ? " (in {$cwd})" : ''));

        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $cwd ?? $this->app->projectRoot());
        if (! is_resource($process)) {
            Output::fail('Failed to start process.');
        }
        fclose($pipes[0]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            Output::fail("Command failed with exit code {$exitCode}.");
        }

        return $exitCode;
    }

    public function isBundlePath(string $relativePath): bool
    {
        $relativePath = $this->normalizePath($relativePath);
        foreach ($this->app->configuration()->bundleDirs() as $dir) {
            if ($relativePath === $dir || str_starts_with($relativePath, $dir.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{files: array<string, string>} */
    public function loadPushManifest(bool $full): array
    {
        $path = $this->app->pushManifestPath();
        if ($full || ! is_file($path)) {
            return ['files' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['files']) || ! is_array($decoded['files'])) {
            return ['files' => []];
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $excludePatterns
     * @param  array<string, string>  $previousManifest
     * @param  'files'|'bundle'|'all'  $scope
     * @return array<string, string>
     */
    public function diffChangedFiles(
        string $prodfiles,
        array $excludePatterns,
        array $previousManifest,
        bool $full,
        string $scope
    ): array {
        $toUpload = [];

        foreach ($this->collectFiles($prodfiles, $excludePatterns) as $relative) {
            $bundled = $this->isBundlePath($relative);
            if ($scope === 'files' && $bundled) {
                continue;
            }
            if ($scope === 'bundle' && ! $bundled) {
                continue;
            }

            $hash = md5_file($prodfiles.'/'.$relative);
            if ($hash === false) {
                Output::fail("Could not hash file: {$relative}");
            }

            if ($full || ! isset($previousManifest[$relative]) || $previousManifest[$relative] !== $hash) {
                $toUpload[$relative] = $hash;
            }
        }

        ksort($toUpload);

        return $toUpload;
    }

    /** @param array<string, string> $env */
    public function connectSftp(array $env): SFTP
    {
        $host = $env['PROD_SSH_HOST'];
        $port = (int) $env['PROD_SSH_PORT'];
        $user = $env['PROD_SSH_USER'];

        Output::info("Connecting to {$user}@{$host}:{$port}...");

        $sftp = new SFTP($host, $port);
        $sftp->setTimeout(120);

        if (! $this->login($sftp, $env)) {
            Output::fail('SFTP login failed. Set PROD_SSH_PASSWORD or PROD_SSH_KEY in deploy/deploy.env.');
        }

        return $sftp;
    }

    /** @param array<string, string> $env */
    public function connectSsh(array $env): SSH2
    {
        $host = $env['PROD_SSH_HOST'];
        $port = (int) $env['PROD_SSH_PORT'];
        $user = $env['PROD_SSH_USER'];

        Output::info("Connecting to {$user}@{$host}:{$port}...");

        $ssh = new SSH2($host, $port);
        $ssh->setTimeout(120);

        if (! $this->login($ssh, $env)) {
            Output::fail('SSH login failed. Set PROD_SSH_PASSWORD or PROD_SSH_KEY in deploy/deploy.env.');
        }

        return $ssh;
    }

    /** @param array<string, string> $env */
    private function login(SFTP|SSH2 $client, array $env): bool
    {
        if (! empty($env['PROD_SSH_KEY']) && is_file($env['PROD_SSH_KEY'])) {
            $key = PublicKeyLoader::load((string) file_get_contents($env['PROD_SSH_KEY']));
            if ($client->login($env['PROD_SSH_USER'], $key)) {
                return true;
            }
        }

        if (isset($env['PROD_SSH_PASSWORD']) && $env['PROD_SSH_PASSWORD'] !== '') {
            return $client->login($env['PROD_SSH_USER'], $env['PROD_SSH_PASSWORD']);
        }

        return false;
    }

    public function runRemoteShell(SSH2 $ssh, string $remotePath, string $shellCommand): int
    {
        $remotePath = rtrim(str_replace('\\', '/', $remotePath), '/');
        $output = $ssh->exec('cd '.escapeshellarg($remotePath).' && '.$shellCommand);

        if (is_string($output) && $output !== '') {
            echo $output;
            if (! str_ends_with($output, "\n")) {
                echo PHP_EOL;
            }
        }

        $exitCode = $ssh->getExitStatus();

        return $exitCode === false ? 0 : (int) $exitCode;
    }

    /** @param list<string> $args */
    public function runRemote(array $args): int
    {
        if ($args === []) {
            Output::fail('Usage: prod-deploy remote <command> [args...]');
        }

        $env = $this->loadDeployEnv();
        $remotePath = rtrim(str_replace('\\', '/', $env['PROD_REMOTE_PATH']), '/');
        $shell = implode(' ', array_map('escapeshellarg', $args));

        Output::info('Running remote: '.implode(' ', $args));

        $ssh = $this->connectSsh($env);
        $exitCode = $this->runRemoteShell($ssh, $remotePath, $shell);

        if ($exitCode !== 0) {
            Output::fail("Remote command failed with exit code {$exitCode}.");
        }

        return 0;
    }

    /**
     * @param  array<string, string>  $toUpload
     */
    public function uploadFiles(SFTP $sftp, string $prodfiles, string $remoteBase, array $toUpload): int
    {
        $total = count($toUpload);
        if ($total === 0) {
            return 0;
        }

        $uploaded = 0;
        foreach (array_keys($toUpload) as $relative) {
            $localPath = $prodfiles.'/'.$relative;
            $remotePath = $remoteBase.'/'.str_replace('\\', '/', $relative);
            $remoteDir = dirname($remotePath);

            if ($remoteDir !== '.' && $remoteDir !== $remoteBase && ! $sftp->is_dir($remoteDir)) {
                if (! $sftp->mkdir($remoteDir, -1, true)) {
                    Output::fail("Could not create remote directory: {$remoteDir}");
                }
            }

            if (! $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
                Output::fail("Upload failed: {$relative}");
            }

            $uploaded++;
            if ($uploaded === $total || $uploaded % 25 === 0) {
                Output::info("Uploaded {$uploaded}/{$total} item(s) (".max(0, $total - $uploaded).' remaining)...');
            }
        }

        return $uploaded;
    }

    /**
     * @param  array<string, string>  $previousManifest
     * @param  array<string, string>  $updates
     * @param  list<string>  $localFiles
     */
    public function savePushManifest(
        array $previousManifest,
        array $updates,
        array $localFiles,
        string $remoteBase,
        string $note
    ): void {
        $files = array_merge($previousManifest, $updates);
        foreach (array_keys($files) as $existing) {
            if (! in_array($existing, $localFiles, true)) {
                unset($files[$existing]);
            }
        }

        file_put_contents(
            $this->app->pushManifestPath(),
            json_encode([
                'uploaded_at' => date('c'),
                'remote_path' => $remoteBase,
                'last_note' => $note,
                'files' => $files,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    public function saveBuildManifest(int $fileCount): void
    {
        file_put_contents(
            $this->app->buildManifestPath(),
            json_encode([
                'built_at' => date('c'),
                'file_count' => $fileCount,
                'project_root' => $this->app->projectRoot(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    /** @param list<string> $dirs */
    public function createBundleZip(string $prodfiles, array $dirs): string
    {
        if (! class_exists(ZipArchive::class)) {
            Output::fail('PHP zip extension (ext-zip) is required.');
        }

        if ($dirs === []) {
            Output::fail('No bundle_dirs configured. Set them in deploy/config.php (for example vendor or node_modules).');
        }

        $zipPath = $this->app->bundleZipPath();
        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            Output::fail('Could not create deploy/bundle.zip');
        }

        $added = 0;
        foreach ($dirs as $dir) {
            $source = $prodfiles.'/'.$dir;
            if (! is_dir($source)) {
                $zip->close();
                Output::fail("Staging folder is missing {$dir}/. Run prod-deploy build first, and do not exclude that directory.");
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $relative = $dir.'/'.$this->relativePath($file->getPathname(), $source);
                if (! $zip->addFile($file->getPathname(), $relative)) {
                    $zip->close();
                    Output::fail("Could not add file to zip: {$relative}");
                }
                $added++;
            }
        }

        if ($added === 0) {
            $zip->close();
            Output::fail('Bundle directories are empty.');
        }

        if (! $zip->close()) {
            Output::fail('Could not finalize deploy/bundle.zip');
        }

        return $zipPath;
    }

    /**
     * @param  list<string>  $buildExcludes
     * @return array<string, string>
     */
    public function resolveTargetFileMap(string $relative, array $buildExcludes, bool $applyPushExcludes): array
    {
        $root = $this->app->projectRoot();
        $prodfiles = $this->app->prodfilesDir();
        $relative = $this->normalizePath($relative);
        $map = [];

        $source = $root.'/'.$relative;
        $staging = $prodfiles.'/'.$relative;

        if (is_dir($source)) {
            foreach ($this->collectFiles($source, $buildExcludes, $relative) as $fileRelative) {
                $map[$this->normalizePath($relative.'/'.$fileRelative)] = $source.'/'.$fileRelative;
            }
        } elseif (is_file($source)) {
            $map[$relative] = $source;
        } elseif (is_dir($staging)) {
            foreach ($this->collectFiles($staging, [], $relative) as $fileRelative) {
                $map[$this->normalizePath($relative.'/'.$fileRelative)] = $staging.'/'.$fileRelative;
            }
        } elseif (is_file($staging)) {
            $map[$relative] = $staging;
        }

        if (! $applyPushExcludes) {
            return $map;
        }

        $pushExcludes = $this->loadExcludePatterns('exclude-push.txt');
        foreach (array_keys($map) as $path) {
            if ($this->pathMatchesExclude($path, $pushExcludes)) {
                unset($map[$path]);
            }
        }

        return $map;
    }

    /** @param array<string, string> $toUpload */
    public function dryRunList(array $toUpload): void
    {
        foreach (array_keys($toUpload) as $relative) {
            echo "  would upload: {$relative}".PHP_EOL;
        }
    }

    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2).' '.$units[$unit];
    }
}
