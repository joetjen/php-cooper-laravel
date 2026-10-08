<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests;

use JOetjen\CooperConfig\ConfigError;
use JOetjen\CooperLaravel\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The tags every load has: Laravel's path helpers, `!php_const` and
 * `!php`, named exactly as the PHP they stand for.
 */
#[RunTestsInSeparateProcesses]
final class TagsTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pathHelpers(): iterable
    {
        yield 'base_path' => ['base_path', ''];
        yield 'app_path' => ['app_path', 'app'];
        yield 'config_path' => ['config_path', 'config'];
        yield 'database_path' => ['database_path', 'database'];
        yield 'lang_path' => ['lang_path', 'lang'];
        yield 'public_path' => ['public_path', 'public'];
        yield 'resource_path' => ['resource_path', 'resources'];
        yield 'storage_path' => ['storage_path', 'storage'];
    }

    #[DataProvider('pathHelpers')]
    public function testAPathHelperIsEvaluatedOnTheRunningMachine(string $helper, string $directory): void
    {
        $this->project->casc("paths {\n  file = !{$helper}(\"x/y.txt\")\n  none = !{$helper}(\"\")\n}");

        $paths = $this->project->console()['config']->get('paths');
        $root = $directory === '' ? $this->project->dir : "{$this->project->dir}/{$directory}";

        self::assertSame("{$root}/x/y.txt", $paths['file']);
        self::assertSame($root, $paths['none']);
    }

    public function testAPathHelperTakesItsArgumentFromTheEnvironmentToo(): void
    {
        self::setEnv('COOPER_LARAVEL_LOG', 'logs/app.log');
        $this->project->casc('logging.channels.single.path = !storage_path(${COOPER_LARAVEL_LOG})');

        self::assertSame("{$this->project->dir}/storage/logs/app.log", $this->project->console()['config']->get('logging.channels.single.path'));
    }

    public function testPhpConstGivesAConstantsValue(): void
    {
        $this->project->casc("constants {\n  class = !php_const(\"PDO::ERRMODE_EXCEPTION\")\n  global = !php_const(\"PHP_INT_SIZE\")\n}");

        self::assertSame(['class' => \PDO::ERRMODE_EXCEPTION, 'global' => PHP_INT_SIZE], $this->project->console()['config']->get('constants'));
    }

    public function testAnUndefinedConstantFailsTheLoad(): void
    {
        $this->project->casc('constants.missing = !php_const("Nope::NOT_THERE")');

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('Nope::NOT_THERE');

        $this->project->console();
    }

    public function testPhpRunsItsCodeAtLoadAndYieldsTheValue(): void
    {
        $this->project->casc(<<<'CASC'
            app {
              providers_count = !php("""count([1, 2, 3])""")
              key_length = !php('strlen(str_repeat("a", 32))')
            }
            CASC);

        $config = $this->project->console()['config'];

        self::assertSame(3, $config->get('app.providers_count'));
        self::assertSame(32, $config->get('app.key_length'));
    }

    public function testPhpRunsInTheApplicationsContext(): void
    {
        $this->project->casc(<<<'CASC'
            app {
              base = !php("""$app->basePath('x')""")
              helper = !php("""storage_path('y')""")
            }
            CASC);

        $config = $this->project->console()['config'];

        self::assertSame("{$this->project->dir}/x", $config->get('app.base'));
        self::assertSame("{$this->project->dir}/storage/y", $config->get('app.helper'));
    }

    public function testPhpRefusesAnythingButALiteralString(): void
    {
        self::setEnv('COOPER_LARAVEL_CODE', 'exit(3)');
        $this->project->casc('app.name = !php(${COOPER_LARAVEL_CODE})');

        try {
            $this->project->console();
            self::fail('expected the load to be refused');
        } catch (ConfigError $e) {
            self::assertStringContainsString('!php(...) takes only a string literal written in the document', $e->getMessage());
            self::assertStringContainsString('at app.name', $e->getMessage());
            self::assertStringContainsString("{$this->project->dir}/config/config.casc", $e->getMessage());
        }
    }

    public function testPhpRefusesAnInterpolatedStringAndAVariable(): void
    {
        $this->project->casc("@code = \"1\"\napp.name = !php(@{code})");

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('!php(...) takes only a string literal written in the document');

        $this->project->console();
    }

    public function testAnExceptionInPhpFailsTheLoadNamingTheFileAndTheKey(): void
    {
        $this->project->casc("app {\n  name = !php(\"\"\"throw new RuntimeException('no name today')\"\"\")\n}");

        try {
            $this->project->console();
            self::fail('expected the load to fail');
        } catch (ConfigError $e) {
            self::assertStringContainsString('!php(...) failed at app.name: no name today', $e->getMessage());
            self::assertStringContainsString("{$this->project->dir}/config/config.casc", $e->getMessage());
            self::assertStringContainsString('line 4', $e->getMessage());
        }
    }

    public function testAParseErrorInPhpFailsTheLoadNamingTheKey(): void
    {
        $this->project->casc('app.name = !php("""1 +""")');

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('!php(...) failed at app.name');

        $this->project->console();
    }

    public function testAnApplicationsOwnTagOfTheSameNameWins(): void
    {
        $this->project->casc('app.name = !storage_path("x")');
        $app = $this->project->app();
        $app->singleton(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static fn () => new \JOetjen\CooperLaravel\Bootstrap\LoadConfiguration([
            'tags' => ['storage_path' => static fn (mixed $path): string => "mine:{$path}"],
        ]));
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        self::assertSame('mine:x', $app['config']->get('app.name'));
    }
}
