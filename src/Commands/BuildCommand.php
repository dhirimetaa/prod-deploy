<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

use Dhirimetaa\ProdDeploy\Support\Output;

final class BuildCommand extends Command
{
    public function run(): int
    {
        $config = $this->app->configuration();
        $root = $this->app->projectRoot();
        $prodfiles = $this->app->prodfilesDir();
        $excludes = $this->kernel->loadExcludePatterns('exclude-build.txt');

        if (! $this->hasFlag('--skip-prebuild') && $config->prebuild() !== null) {
            Output::info('Running prebuild...');
            $this->kernel->runProcess($config->prebuild(), $root);
        }

        Output::info('Preparing '.$config->prodfilesDir().'/ ...');
        if (is_dir($prodfiles)) {
            $this->kernel->removeDirectory($prodfiles);
        }
        $this->kernel->ensureDirectory($prodfiles);

        Output::info('Copying project files into staging...');
        $copied = 0;
        foreach ($this->kernel->collectFiles($root, $excludes) as $relative) {
            $this->kernel->copyFile($root.'/'.$relative, $prodfiles.'/'.$relative);
            $copied++;
        }

        Output::info("Copied {$copied} file(s).");

        if (! $this->hasFlag('--skip-postbuild') && $config->postbuild() !== null) {
            Output::info('Running postbuild inside staging...');
            $this->kernel->runProcess($config->postbuild(), $prodfiles);
        }

        foreach ($config->verifyAfterBuild() as $path) {
            if (! file_exists($prodfiles.'/'.$path)) {
                Output::fail("Build check failed, missing: {$path}");
            }
        }

        $this->kernel->saveBuildManifest($copied);
        Output::info('Build complete.');

        return 0;
    }
}
