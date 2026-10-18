<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Commands;

final class RemoteCommand extends Command
{
    public function run(): int
    {
        $args = array_values(array_filter(
            $this->args,
            static fn (string $arg): bool => $arg !== '--'
        ));

        return $this->kernel->runRemote($args);
    }
}
