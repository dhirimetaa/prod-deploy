<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

use Dhirimetaa\ProdDeploy\Application;
use Dhirimetaa\ProdDeploy\DeployKernel;

abstract class Command
{
    protected Application $app;

    protected DeployKernel $kernel;

    /** @var list<string> */
    protected array $args;

    public function __construct(Application $app, array $args)
    {
        $this->app = $app;
        $this->kernel = new DeployKernel($app);
        $this->args = $args;
    }

    abstract public function run(): int;

    protected function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->args, true);
    }

    /** @return list<string> */
    protected function positionalArgs(): array
    {
        return array_values(array_filter(
            $this->args,
            static fn (string $arg): bool => $arg !== '--' && ! str_starts_with($arg, '--')
        ));
    }
}
