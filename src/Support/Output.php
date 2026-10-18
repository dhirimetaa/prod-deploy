<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Support;

final class Output
{
    public static function info(string $message): void
    {
        echo 'prod-deploy: '.$message.PHP_EOL;
    }

    public static function fail(string $message, int $code = 1): never
    {
        fwrite(STDERR, 'prod-deploy: '.$message.PHP_EOL);
        exit($code);
    }
}
