<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Bootstrap;

use Illuminate\Foundation\Bootstrap\LoadConfiguration as LaravelLoadConfiguration;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\CooperConfig\ConfigError;
use JOetjen\CooperLaravel\Bootstrap\LoadConfiguration;
use JOetjen\CooperLaravel\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * CASC in place of Laravel's configuration files: `config/config.casc`
 * fills the configuration repository, one top-level block per
 * configuration "file", over the framework's and the packages' defaults.
 */
#[RunTestsInSeparateProcesses]
final class LoadConfigurationTest extends IntegrationTestCase
{
    public function testTheConfigurationRepositoryIsFilledFromTheCascDocument(): void
    {
        $this->project->casc(<<<'CASC'
            app { name = "From CASC" }
            database.connections.mysql.host = "db.internal"
            CASC);

        $app = $this->project->console();

        self::assertSame('From CASC', $app['config']->get('app.name'));
        self::assertSame('db.internal', $app['config']->get('database.connections.mysql.host'));
    }

    public function testTheHttpKernelLoadsTheSameConfigurationAsTheConsoleKernel(): void
    {
        $this->project->casc('app { name = "Over HTTP" }');

        self::assertSame('Over HTTP', $this->project->http()['config']->get('app.name'));
    }

    public function testEachTopLevelBlockIsOneConfigurationFileEvenOneLaravelHasNever(): void
    {
        $this->project->casc('reports { per_page = 25 }');

        self::assertSame(['per_page' => 25], $this->project->console()['config']->get('reports'));
    }

    public function testTheApplicationsOwnConfigPhpFilesAreIgnored(): void
    {
        $this->project->write('config/app.php', "<?php return ['name' => 'From PHP', 'locale' => 'xx'];");
        $this->project->write('config/custom.php', "<?php return ['only' => 'in php'];");
        $this->project->casc('app { name = "From CASC" }');

        $config = $this->project->console()['config'];

        self::assertSame('From CASC', $config->get('app.name'));
        self::assertNotSame('xx', $config->get('app.locale'));
        self::assertFalse($config->has('custom'));
    }

    public function testTheFrameworksDefaultsArriveUnderTheCascValues(): void
    {
        $this->project->casc(<<<'CASC'
            app { name = "Mine" }
            database.connections.reporting {
              driver   = "sqlite"
              database = ":memory:"
            }
            CASC);

        $config = $this->project->console()['config'];

        // A key the document does not set keeps Laravel's own default ...
        self::assertSame('AES-256-CBC', $config->get('app.cipher'));
        // ... and a connection it adds joins Laravel's, as a config/database.php would.
        self::assertSame('sqlite', $config->get('database.connections.reporting.driver'));
        self::assertSame('mysql', $config->get('database.connections.mysql.driver'));
    }

    public function testAPackagesDefaultsArriveUnderTheCascValues(): void
    {
        $this->project->casc('probe { name = "probe, configured" }');

        $config = $this->project->console()['config'];

        self::assertSame('probe, configured', $config->get('probe.name'));
        self::assertSame(['a', 'b'], $config->get('probe.tags'));
        self::assertSame(60, $config->get('probe_limits.per_minute'));
    }

    public function testValuesArriveAsPlainPhpValuesUnderCooperConfigsConversion(): void
    {
        $this->project->casc(<<<'CASC'
            limits {
              upload   = 2MiB
              timeout  = 1500ms
              address  = 10.0.0.0/8
              level    = info
              point    = (52.52, 13.405)
              released = 2026-10-06
            }
            CASC);

        self::assertSame(
            ['upload' => 2097152, 'timeout' => 1500, 'address' => '10.0.0.0/8', 'level' => 'info', 'point' => [52.52, 13.405], 'released' => '2026-10-06'],
            $this->project->console()['config']->get('limits'),
        );
    }

    public function testSecretsAreRevealedByDefault(): void
    {
        $this->project->casc('database.connections.mysql { *password = "s3cret" }');

        self::assertSame('s3cret', $this->project->console()['config']->get('database.connections.mysql.password'));
    }

    public function testABlockMayKeepItsSecretsAsSecrets(): void
    {
        $this->project->casc("vault {\n  cooper-secrets = keep\n  *token = \"s3cret\"\n}");

        $token = $this->project->console()['config']->get('vault.token');

        self::assertInstanceOf(CooperSecret::class, $token);
        self::assertSame('s3cret', $token->reveal());
    }

    public function testCooperEnvChoosesTheOverlayFromLaravelsAppEnv(): void
    {
        $this->project->casc("app { name = \"base\" }\nimport \"\${COOPER_ENV}/*.casc\"");
        $this->project->casc('app { name = "testing overlay" }', 'config/test/app.casc');
        $this->project->casc('app { name = "production overlay" }', 'config/prod/app.casc');
        self::setEnv('APP_ENV', 'testing');

        $app = $this->project->console();

        self::assertSame('testing overlay', $app['config']->get('app.name'));
        self::assertSame('testing', $app->environment());
    }

    public function testAProductionAppEnvReadsTheProdOverlay(): void
    {
        $this->project->casc("app { name = \"base\" }\nimport \"\${COOPER_ENV}/*.casc\"");
        $this->project->casc('app { name = "production overlay" }', 'config/prod/app.casc');
        $this->project->write('.env', "APP_ENV=production\n");

        self::assertSame('production overlay', $this->project->console()['config']->get('app.name'));
    }

    public function testAppDebugSetTo1LoadsAsTrue(): void
    {
        // `APP_DEBUG=1` is how many a Laravel `.env` spells it; `!bool`
        // once read only `true`/`false`, and refused this one.
        $this->project->casc('app { debug = !bool(${APP_DEBUG:false}) }');
        $this->project->write('.env', "APP_DEBUG=1\n");

        self::assertTrue($this->project->console()['config']->get('app.debug'));
    }

    public function testArtisansEnvOptionChoosesTheOverlayAndTheDotenvFile(): void
    {
        $this->project->casc("app { name = \"base\" }\nimport \"\${COOPER_ENV}/*.casc\"");
        $this->project->casc('app { name = ${COOPER_LARAVEL_PROBE} }', 'config/prod/app.casc');
        $this->project->write('.env', "APP_ENV=local\n");
        $this->project->write('.env.prod', "COOPER_LARAVEL_PROBE=\"production overlay\"\n");
        $_SERVER['argv'] = ['artisan', 'about', '--env=production'];

        $app = $this->project->console();

        self::assertSame('production overlay', $app['config']->get('app.name'));
        self::assertSame('production', $app->environment());
    }

    public function testTheEnvironmentAndTimezoneComeFromTheLoadedConfiguration(): void
    {
        $this->project->casc('app { env = "staging", timezone = "Europe/Berlin" }');

        $app = $this->project->console();

        self::assertSame('staging', $app->environment());
        self::assertSame('Europe/Berlin', date_default_timezone_get());
    }

    public function testAMissingDocumentLeavesTheFrameworksDefaults(): void
    {
        $config = $this->project->console()['config'];

        self::assertSame('AES-256-CBC', $config->get('app.cipher'));
        self::assertSame('probe', $config->get('probe.name'));
    }

    public function testADocumentThatDoesNotLoadFailsTheBootNamingTheFile(): void
    {
        $this->project->casc('app { name = ${COOPER_LARAVEL_SURELY_UNSET} }');

        try {
            $this->project->console();
            self::fail('expected the boot to fail');
        } catch (ConfigError $e) {
            self::assertStringContainsString("{$this->project->dir}/config/config.casc", $e->getMessage());
            self::assertStringContainsString('COOPER_LARAVEL_SURELY_UNSET', $e->getMessage());
        }
    }

    public function testTheApplicationsOwnLoadOptionsArePassedThrough(): void
    {
        $this->project->casc('app { name = !{vault:app/name} }');
        $app = $this->project->app();
        $app->singleton(LaravelLoadConfiguration::class, static fn (): LoadConfiguration => new LoadConfiguration([
            'resolvers' => ['vault' => static fn (string $path): string => "secret at {$path}"],
        ]));
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        self::assertSame('secret at app/name', $app['config']->get('app.name'));
    }

    public function testTheDocumentMayLiveElsewhere(): void
    {
        $this->project->casc('app { name = "elsewhere" }', 'settings/main.casc');
        $app = $this->project->app();
        $app->singleton(LaravelLoadConfiguration::class, fn (): LoadConfiguration => new LoadConfiguration([], "{$this->project->dir}/settings/main.casc"));
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        self::assertSame('elsewhere', $app['config']->get('app.name'));
    }
}
