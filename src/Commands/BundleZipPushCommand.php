<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

use Dhirimetaa\ProdDeploy\Support\Output;
use phpseclib3\Net\SFTP;

final class BundleZipPushCommand extends Command
{
    public function run(): int
    {
        $prodfiles = $this->app->prodfilesDir();
        if (! is_dir($prodfiles)) {
            Output::fail('Staging folder not found. Run prod-deploy build first.');
        }

        $dirs = $this->app->configuration()->bundleDirs();
        $zip = $this->kernel->createBundleZip($prodfiles, $dirs);
        $bytes = filesize($zip);
        Output::info('Bundle ready'.($bytes !== false ? ' ('.$this->kernel->formatBytes((int) $bytes).')' : '').'.');

        if ($this->hasFlag('--dry-run')) {
            Output::info('Would upload deploy/bundle.zip and leave it on the server for extraction.');

            return 0;
        }

        $env = $this->kernel->loadDeployEnv();
        $remoteBase = rtrim(str_replace('\\', '/', $env['PROD_REMOTE_PATH']), '/');
        $sftp = $this->kernel->connectSftp($env);
        $remoteZip = $remoteBase.'/bundle.zip';

        if (! $sftp->put($remoteZip, $zip, SFTP::SOURCE_LOCAL_FILE)) {
            Output::fail('Upload failed: bundle.zip');
        }

        Output::info("Uploaded bundle.zip to {$remoteBase}.");
        Output::info('Extract on the server with:');
        Output::info('  prod-deploy remote unzip -o bundle.zip');
        Output::info('  prod-deploy remote rm bundle.zip');
        Output::info('Bundled directories: '.implode(', ', $dirs));

        return 0;
    }
}
