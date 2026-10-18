<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Console;

use Dhirimetaa\ProdDeploy\Application;
use Dhirimetaa\ProdDeploy\Commands\BuildCommand;
use Dhirimetaa\ProdDeploy\Commands\BundleZipCommand;
use Dhirimetaa\ProdDeploy\Commands\BundleZipPushCommand;
use Dhirimetaa\ProdDeploy\Commands\InitCommand;
use Dhirimetaa\ProdDeploy\Commands\PushCommand;
use Dhirimetaa\ProdDeploy\Commands\PushTargetCommand;
use Dhirimetaa\ProdDeploy\Commands\RemoteCommand;

final class Cli
{
    /** @var array<string, class-string> */
    private const COMMANDS = [
        'init' => InitCommand::class,
        'build' => BuildCommand::class,
        'push' => PushCommand::class,
        'push:target' => PushTargetCommand::class,
        'bundle:zip' => BundleZipCommand::class,
        'bundle:zip-push' => BundleZipPushCommand::class,
        'remote' => RemoteCommand::class,
    ];

    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        $args = array_slice($argv, 1);

        if ($args === [] || in_array($args[0], ['-h', '--help', 'help'], true)) {
            self::printHelp();

            return 0;
        }

        $commandName = $args[0];

        if ($commandName === '--version' || $commandName === '-V') {
            echo 'prod-deploy '.self::version().PHP_EOL;

            return 0;
        }

        if (! isset(self::COMMANDS[$commandName])) {
            fwrite(STDERR, "Unknown command: {$commandName}\n\n");
            self::printHelp();

            return 1;
        }

        $app = Application::boot();
        $class = self::COMMANDS[$commandName];

        return (new $class($app, array_slice($args, 1)))->run();
    }

    private static function version(): string
    {
        $composer = dirname(__DIR__, 2).'/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data) && isset($data['version'])) {
                return (string) $data['version'];
            }
        }

        return 'dev';
    }

    private static function printHelp(): void
    {
        echo <<<'HELP'
prod-deploy — stage a local folder and upload it to cPanel or any SFTP host

Usage:
  prod-deploy <command> [options] [args...]

Commands:
  init               Create deploy/ (SSH env, excludes, config)
  build              Copy the project into prodfiles/, with optional pre/post commands
  push               Incremental SFTP upload of staged files
  push:target        Upload specific paths
  bundle:zip         Zip configured bundle_dirs into deploy/bundle.zip
  bundle:zip-push    Upload that zip as one file (large folders such as vendor)
  remote             Run a shell command on the server inside PROD_REMOTE_PATH

Options:
  build              [--skip-prebuild] [--skip-postbuild]
  push               [--dry-run] [--full]
  push:target        [--dry-run] [--ignore-excludes] <path> [path...]
  bundle:zip-push    [--dry-run]
  init               [--force]

Examples:
  prod-deploy init
  prod-deploy build
  prod-deploy push --dry-run
  prod-deploy push
  prod-deploy push:target public/index.html
  prod-deploy remote ls -la

Run it from the project folder you want to deploy.
Set PROD_DEPLOY_ROOT if that folder is not the current directory.

HELP;
    }
}
