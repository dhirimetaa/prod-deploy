<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

use Dhirimetaa\ProdDeploy\Support\Output;

final class BundleZipCommand extends Command
{
    public function run(): int
    {
        $prodfiles = $this->app->prodfilesDir();
        if (! is_dir($prodfiles)) {
            Output::fail('Staging folder not found. Run prod-deploy build first.');
        }

        $zip = $this->kernel->createBundleZip($prodfiles, $this->app->configuration()->bundleDirs());
        $bytes = filesize($zip);
        Output::info('Wrote '.$zip.($bytes !== false ? ' ('.$this->kernel->formatBytes($bytes).')' : ''));

        return 0;
    }
}
