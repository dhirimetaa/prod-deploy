<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy;

final class Configuration
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function load(string $projectRoot): self
    {
        $defaults = [
            'prodfiles_dir' => 'prodfiles',
            'config_dir' => 'deploy',
            'prebuild' => null,
            'postbuild' => null,
            'bundle_dirs' => [],
            'verify_after_build' => [],
        ];

        $projectConfig = $projectRoot.'/deploy/config.php';
        if (is_file($projectConfig)) {
            $loaded = require $projectConfig;
            if (is_array($loaded)) {
                $defaults = array_merge($defaults, $loaded);
            }
        }

        return new self($defaults);
    }

    public function prodfilesDir(): string
    {
        return (string) $this->config['prodfiles_dir'];
    }

    public function configDir(): string
    {
        return (string) $this->config['config_dir'];
    }

    /** @return list<string>|null */
    public function prebuild(): ?array
    {
        $command = $this->config['prebuild'];
        if ($command === null || $command === [] || $command === '') {
            return null;
        }

        /** @var list<string> $command */
        return array_values($command);
    }

    /** @return list<string>|null */
    public function postbuild(): ?array
    {
        $command = $this->config['postbuild'];
        if ($command === null || $command === [] || $command === '') {
            return null;
        }

        /** @var list<string> $command */
        return array_values($command);
    }

    /** @return list<string> */
    public function bundleDirs(): array
    {
        /** @var list<string> $dirs */
        $dirs = $this->config['bundle_dirs'];

        return array_values(array_map(
            static fn (string $dir): string => trim(str_replace('\\', '/', $dir), '/'),
            $dirs
        ));
    }

    /** @return list<string> */
    public function verifyAfterBuild(): array
    {
        /** @var list<string> $paths */
        $paths = $this->config['verify_after_build'];

        return $paths;
    }
}
