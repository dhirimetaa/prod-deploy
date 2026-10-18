<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

use Dhirimetaa\ProdDeploy\Support\Output;

final class PushCommand extends Command
{
    public function run(): int
    {
        $dryRun = $this->hasFlag('--dry-run');
        $full = $this->hasFlag('--full');
        $prodfiles = $this->app->prodfilesDir();
        $excludes = $this->kernel->loadExcludePatterns('exclude-push.txt');

        if (! is_dir($prodfiles)) {
            Output::fail('Staging folder not found. Run prod-deploy build first.');
        }

        $manifest = $this->kernel->loadPushManifest($full);
        $previous = $manifest['files'];
        $toUpload = $this->kernel->diffChangedFiles($prodfiles, $excludes, $previous, $full, 'files');
        $bundlePending = $this->kernel->diffChangedFiles($prodfiles, $excludes, $previous, $full, 'bundle');

        if ($toUpload === []) {
            Output::info('Nothing to upload — staged files match the last successful push.');
        } else {
            Output::info(count($toUpload).' file(s) to upload.');
        }

        if ($bundlePending !== []) {
            Output::info(count($bundlePending).' bundled file(s) changed and are not part of push.');
            Output::info('Run prod-deploy bundle:zip-push for those directories.');
        }

        if ($toUpload === []) {
            return 0;
        }

        if ($dryRun) {
            $this->kernel->dryRunList($toUpload);

            return 0;
        }

        $env = $this->kernel->loadDeployEnv();
        $remoteBase = rtrim(str_replace('\\', '/', $env['PROD_REMOTE_PATH']), '/');
        $sftp = $this->kernel->connectSftp($env);
        $uploaded = $this->kernel->uploadFiles($sftp, $prodfiles, $remoteBase, $toUpload);
        $localFiles = $this->kernel->collectFiles($prodfiles, $excludes);
        $this->kernel->savePushManifest($previous, $toUpload, $localFiles, $remoteBase, 'push');
        Output::info("Push complete. {$uploaded} file(s) uploaded to {$remoteBase}.");

        return 0;
    }
}
