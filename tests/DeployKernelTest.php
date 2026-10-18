<?php

declare(strict_types=1);

namespace Dhirimetaa\ProdDeploy\Tests;

use Dhirimetaa\ProdDeploy\Application;
use Dhirimetaa\ProdDeploy\DeployKernel;
use PHPUnit\Framework\TestCase;

final class DeployKernelTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        Application::reset();
        $this->root = sys_get_temp_dir().'/prod-deploy-test-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/deploy', 0777, true);
        mkdir($this->root.'/public', 0777, true);
        mkdir($this->root.'/keep', 0777, true);
        file_put_contents($this->root.'/public/index.html', '<h1>ok</h1>');
        file_put_contents($this->root.'/keep/app.txt', 'hello');
        file_put_contents($this->root.'/.env', 'SECRET=1');
        file_put_contents($this->root.'/deploy/exclude-build.txt', ".env\n/skip/\n");
        file_put_contents($this->root.'/deploy/config.php', "<?php\nreturn ['bundle_dirs' => ['vendor']];\n");
        mkdir($this->root.'/skip', 0777, true);
        file_put_contents($this->root.'/skip/nope.txt', 'no');
    }

    protected function tearDown(): void
    {
        Application::reset();
        $this->deleteTree($this->root);
    }

    public function test_collect_files_skips_excluded_directories_and_secrets(): void
    {
        $app = Application::boot($this->root);
        $kernel = new DeployKernel($app);
        $files = $kernel->collectFiles($this->root, $kernel->loadExcludePatterns('exclude-build.txt'));

        $this->assertContains('public/index.html', $files);
        $this->assertContains('keep/app.txt', $files);
        $this->assertNotContains('.env', $files);
        $this->assertNotContains('skip/nope.txt', $files);
    }

    public function test_bundle_paths_follow_config(): void
    {
        $app = Application::boot($this->root);
        $kernel = new DeployKernel($app);

        $this->assertTrue($kernel->isBundlePath('vendor/autoload.php'));
        $this->assertFalse($kernel->isBundlePath('public/index.html'));
    }

    public function test_env_file_parses_comments_and_quotes(): void
    {
        $env = $this->root.'/deploy/sample.env';
        file_put_contents($env, "# comment\nPROD_SSH_HOST=\"example.com\"\nPROD_SSH_PORT=22\n\n");

        $app = Application::boot($this->root);
        $values = (new DeployKernel($app))->loadEnvFile($env);

        $this->assertSame('example.com', $values['PROD_SSH_HOST']);
        $this->assertSame('22', $values['PROD_SSH_PORT']);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            is_dir($full) ? $this->deleteTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
