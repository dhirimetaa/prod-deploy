<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

use Dhirimetaa\ProdDeploy\Support\Output;

final class InitCommand extends Command
{
    public function run(): int
    {
        $force = $this->hasFlag('--force');
        $configDir = $this->app->configDir();
        $root = $this->app->projectRoot();

        $this->kernel->ensureDirectory($configDir);

        foreach ([
            'deploy.env.example' => 'deploy.env',
            'config.php.example' => 'config.php',
        ] as $stub => $dest) {
            $target = $configDir.'/'.$dest;
            $source = $this->app->stubPath($stub);
            if (! is_file($source)) {
                Output::fail("Package stub missing: {$stub}");
            }
            if (is_file($target) && ! $force) {
                Output::info("Skipped {$dest} (already exists). Use --force to overwrite.");
                continue;
            }
            copy($source, $target);
            Output::info("Created deploy/{$dest}");
        }

        foreach (['exclude-build.txt', 'exclude-push.txt'] as $file) {
            $target = $configDir.'/'.$file;
            $source = $this->app->packageRoot().'/config/'.$file;
            if (is_file($target) && ! $force) {
                Output::info("Skipped {$file} (already exists).");
                continue;
            }
            copy($source, $target);
            Output::info("Created deploy/{$file}");
        }

        $staging = '/'.$this->app->configuration()->prodfilesDir().'/';
        $configName = $this->app->configuration()->configDir();
        $lines = [
            '',
            '# prod-deploy',
            $staging,
            '/'.$configName.'/deploy.env',
            '/'.$configName.'/.push-manifest.json',
            '/'.$configName.'/.build-manifest.json',
            '/'.$configName.'/*.zip',
        ];

        $gitignorePath = $root.'/.gitignore';
        $marker = trim($staging, '/');
        if (is_file($gitignorePath)) {
            $content = (string) file_get_contents($gitignorePath);
            if (! str_contains($content, $marker)) {
                file_put_contents($gitignorePath, rtrim($content).PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
                Output::info('Updated .gitignore.');
            }
        } else {
            file_put_contents($gitignorePath, ltrim(implode(PHP_EOL, $lines)).PHP_EOL);
            Output::info('Created .gitignore.');
        }

        Output::info('Init complete. Edit deploy/deploy.env with the server SSH details.');
        Output::info('Then: prod-deploy build && prod-deploy push');

        return 0;
    }
}
