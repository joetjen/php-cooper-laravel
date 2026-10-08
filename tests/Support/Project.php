<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Support;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * A scratch Laravel application on disk: Testbench's default skeleton
 * (with its own `config/*.php`, which Cooper must ignore), whose
 * `bootstrap/app.php` is replaced by the one `README.md` tells an
 * application to write, and `bootstrap/providers.php` registering this
 * package's provider and the probe package's.
 *
 * Tests boot it through the real kernels, the way `public/index.php`
 * and `artisan` do, so what they see is what an application sees.
 */
final class Project
{
    /**
     * A minimal `bootstrap/app.php` with the two bindings README.md's
     * installation tells an application to add, written as it says.
     */
    public const BOOTSTRAP_APP = <<<'PHP'
        <?php

        use Illuminate\Foundation\Application;
        use Illuminate\Foundation\Bootstrap\LoadConfiguration;
        use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
        use JOetjen\CooperLaravel\Bootstrap\LoadConfiguration as CooperLoadConfiguration;
        use JOetjen\CooperLaravel\Bootstrap\LoadEnvironmentVariables as CooperLoadEnvironmentVariables;

        $app = Application::configure(basePath: dirname(__DIR__))
            ->withRouting(commands: __DIR__ . '/../routes/console.php')
            ->create();

        // Cooper reads .env and config/config.casc in place of Laravel.
        $app->singleton(LoadEnvironmentVariables::class, CooperLoadEnvironmentVariables::class);
        $app->singleton(LoadConfiguration::class, CooperLoadConfiguration::class);

        return $app;

        PHP;

    private function __construct(public readonly string $dir)
    {
    }

    /**
     * A fresh project in the system's temporary directory.
     */
    public static function create(): self
    {
        $dir = sys_get_temp_dir() . '/cooper-laravel-' . bin2hex(random_bytes(6));
        $skeleton = default_skeleton_path();
        assert(is_string($skeleton));
        self::copy($skeleton, $dir);
        $project = new self((string) realpath($dir));
        $project->write('bootstrap/app.php', self::BOOTSTRAP_APP);
        $project->write('bootstrap/providers.php', <<<'PHP'
            <?php

            return [
                JOetjen\CooperLaravel\CooperServiceProvider::class,
                JOetjen\CooperLaravel\Tests\Support\Fixtures\ProbePackage\ProbeServiceProvider::class,
            ];

            PHP);
        $project->write('routes/console.php', "<?php\n");
        // The skeleton's own bootstrap/cache may carry Testbench's files.
        foreach (glob("{$project->dir}/bootstrap/cache/*.php") ?: [] as $file) {
            unlink($file);
        }

        return $project;
    }

    /**
     * Writes `$content` to `$path`, relative to the project, creating
     * its directory.
     */
    public function write(string $path, string $content): void
    {
        $file = "{$this->dir}/{$path}";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    /**
     * Writes `config/config.casc`, the version header prepended.
     */
    public function casc(string $body, string $path = 'config/config.casc'): void
    {
        $this->write($path, "#@version = 1.0\n\n{$body}\n");
    }

    public function read(string $path): string
    {
        return (string) file_get_contents("{$this->dir}/{$path}");
    }

    public function has(string $path): bool
    {
        return file_exists("{$this->dir}/{$path}");
    }

    /**
     * The application, as `bootstrap/app.php` creates it, not yet booted.
     */
    public function app(): Application
    {
        $app = require "{$this->dir}/bootstrap/app.php";
        assert($app instanceof Application);

        return $app;
    }

    /**
     * The application, bootstrapped by its console kernel, as `artisan` does.
     */
    public function console(): Application
    {
        $app = $this->app();
        $app->make(ConsoleKernel::class)->bootstrap();

        return $app;
    }

    /**
     * The application, bootstrapped by its HTTP kernel, as
     * `public/index.php` does.
     */
    public function http(): Application
    {
        $app = $this->app();
        $kernel = $app->make(HttpKernel::class);
        assert($kernel instanceof \Illuminate\Foundation\Http\Kernel);
        $kernel->bootstrap();

        return $app;
    }

    /**
     * Runs an artisan command in a freshly booted application.
     *
     * @param array<string, mixed> $parameters
     * @return array{0: int, 1: string} the exit status and the output
     */
    public function artisan(string $command, array $parameters = []): array
    {
        $kernel = $this->app()->make(ConsoleKernel::class);
        $status = $kernel->call($command, $parameters);

        return [$status, $kernel->output()];
    }

    public function remove(): void
    {
        self::removeTree($this->dir);
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . substr($item->getPathname(), strlen($from));
            $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
