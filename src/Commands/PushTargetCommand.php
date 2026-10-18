<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

use Dhirimetaa\ProdDeploy\Support\Output;

final class PushTargetCommand extends Command
{
    public function run(): int
    {
        $paths = $this->positionalArgs();
        if ($paths === []) {
            Output::fail('Usage: prod-deploy push:target [--dry-run] [--ignore-excludes] <path> [path...]');
        }

        $dryRun = $this->hasFlag('--dry-run');
        $applyPushExcludes = ! $this->hasFlag('--ignore-excludes');
        $buildExcludes = $this->kernel->loadExcludePatterns('exclude-build.txt');
        $map = [];

        foreach ($paths as $path) {
            $resolved = $this->kernel->resolveTargetFileMap($path, $buildExcludes, $applyPushExcludes);
            if ($resolved === []) {
                Output::fail("No files found for: {$path}");
            }
            $map = array_merge($map, $resolved);
        }

        $toUpload = [];
        foreach ($map as $relative => $absolute) {
            $hash = md5_file($absolute);
            if ($hash === false) {
                Output::fail("Could not hash file: {$relative}");
            }
            $toUpload[$relative] = $hash;
        }

        Output::info(count($toUpload).' file(s) selected.');

        if ($dryRun) {
            $this->kernel->dryRunList($toUpload);

            return 0;
        }

        $env = $this->kernel->loadDeployEnv();
        $remoteBase = rtrim(str_replace('\\', '/', $env['PROD_REMOTE_PATH']), '/');
        $sftp = $this->kernel->connectSftp($env);

        $uploaded = 0;
        foreach ($map as $relative => $absolute) {
            $remotePath = $remoteBase.'/'.$relative;
            $remoteDir = dirname($remotePath);
            if ($remoteDir !== $remoteBase && ! $sftp->is_dir($remoteDir) && ! $sftp->mkdir($remoteDir, -1, true)) {
                Output::fail("Could not create remote directory: {$remoteDir}");
            }
            if (! $sftp->put($remotePath, $absolute, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                Output::fail("Upload failed: {$relative}");
            }
            $uploaded++;
        }

        Output::info("Uploaded {$uploaded} file(s).");

        return 0;
    }
}
