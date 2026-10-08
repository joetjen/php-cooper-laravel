<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Command;

use JOetjen\CooperLaravel\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `cooper:import`: a package's configuration, or the application's own
 * `config/*.php`, moved into CASC -- by reading the PHP, never running it.
 */
#[RunTestsInSeparateProcesses]
final class ImportCommandTest extends IntegrationTestCase
{
    public function testAPackagesConfigurationIsImportedByName(): void
    {
        [$status, $output] = $this->project->artisan('cooper:import', ['package' => 'probe']);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('created config/packages/probe.casc', $output);
        $casc = $this->project->read('config/packages/probe.casc');
        self::assertStringContainsString("probe {\n", $casc);
        self::assertStringContainsString('# Probe Name', $casc);
        self::assertStringContainsString('enabled = !bool(${PROBE_ENABLED:true})', $casc);
        self::assertStringContainsString('hosts = ${PROBE_HOSTS[]:["a.example", "b.example"]}', $casc);
        self::assertStringContainsString('model = !module("Illuminate.Foundation.Auth.User")', $casc);
        self::assertStringContainsString('database = ${PROBE_DATABASE:!database_path("probe.sqlite")}', $casc);
        self::assertStringContainsString('error_mode = !php_const("PDO::ERRMODE_EXCEPTION")', $casc);
        self::assertStringContainsString('prefix = !php("""\Illuminate\Support\Str::slug(', $casc);
    }

    public function testTheImportEndsWithASummary(): void
    {
        [, $output] = $this->project->artisan('cooper:import', ['package' => 'probe']);

        self::assertStringContainsString("15 keys exact, 1 via !php, 0 skipped\n", $output);
        self::assertStringContainsString("via !php:\n  probe.prefix\n", $output);
        self::assertStringEndsWith("0 skipped\nvia !php:\n  probe.prefix\n", $output);
    }

    public function testTheImportedPackageConfigurationLoadsBackToWhatThePhpSaid(): void
    {
        $before = $this->project->console()['config']->get('probe');
        $this->project->artisan('cooper:import', ['package' => 'probe']);

        // The imported file is what is read now; the package's own
        // defaults still arrive underneath, so a key the import got
        // wrong would show as the CASC value, not the PHP one.
        $this->project->casc("import \"packages/*.casc\"\nprobe.name = \"proof the document is read\"");
        $after = $this->project->console()['config']->get('probe');

        self::assertSame('proof the document is read', $after['name']);
        unset($before['name'], $after['name']);
        self::assertSame($before, $after);
    }

    public function testAllImportsEveryPackageConfigurationFound(): void
    {
        [$status, $output] = $this->project->artisan('cooper:import', ['--all' => true]);

        self::assertSame(0, $status, $output);
        self::assertTrue($this->project->has('config/packages/probe.casc'));
        self::assertTrue($this->project->has('config/packages/probe_limits.casc'));
        self::assertTrue($this->project->has('config/packages/probe_queue.casc'));
        self::assertStringContainsString("probe_limits {\n  per_minute = 60\n  burst = -5\n  factor = 1.5\n  strict = false\n  window = nil\n}", $this->project->read('config/packages/probe_limits.casc'));
    }

    public function testAnUnknownPackageNameListsTheKnownOnes(): void
    {
        [$status, $output] = $this->project->artisan('cooper:import', ['package' => 'nope']);

        self::assertSame(1, $status);
        self::assertStringContainsString('no package configuration named "nope"', $output);
        self::assertStringContainsString('probe, probe_limits, probe_queue', $output);
    }

    public function testNothingToImportIsAnError(): void
    {
        [$status, $output] = $this->project->artisan('cooper:import');

        self::assertSame(1, $status);
        self::assertStringContainsString('--all', $output);
    }

    public function testTheMainDocumentIsCreatedImportingThePackagesBeforeTheEnvironment(): void
    {
        $this->project->artisan('cooper:import', ['package' => 'probe']);

        $main = $this->project->read('config/config.casc');
        self::assertMatchesRegularExpression('~import "packages/\*\.casc".*import "\$\{COOPER_ENV\}/\*\.casc"~s', $main);
        self::assertStringNotContainsString('import "app/*.casc"', $main);
        foreach (['dev', 'test', 'prod'] as $env) {
            self::assertTrue($this->project->has("config/{$env}/app.casc"));
        }
    }

    public function testAnExistingMainDocumentGetsThePackagesImportOnceBeforeTheEnvironment(): void
    {
        $this->project->casc("app.name = \"mine\"\n\nimport \"\${COOPER_ENV}/*.casc\"");
        $this->project->casc('', 'config/dev/app.casc');

        $this->project->artisan('cooper:import', ['package' => 'probe']);
        $this->project->artisan('cooper:import', ['package' => 'probe_limits']);

        $main = $this->project->read('config/config.casc');
        self::assertSame(1, substr_count($main, 'import "packages/*.casc"'));
        self::assertLessThan(strpos($main, '${COOPER_ENV}'), strpos($main, 'import "packages/*.casc"'));
        self::assertStringContainsString('app.name = "mine"', $main);
    }

    public function testAnExistingFileThatWouldChangeIsShownAsADiffAndLeftAlone(): void
    {
        $this->project->artisan('cooper:import', ['package' => 'probe_limits']);
        $this->project->write('config/packages/probe_limits.casc', "#@version = 1.0\n\nprobe_limits.per_minute = 1\n");

        [$status, $output] = $this->project->artisan('cooper:import', ['package' => 'probe_limits']);

        self::assertSame(1, $status);
        self::assertStringContainsString('not overwriting config/packages/probe_limits.casc', $output);
        self::assertStringContainsString('-probe_limits.per_minute = 1', $output);
        self::assertStringContainsString('+  per_minute = 60', $output);
        self::assertSame("#@version = 1.0\n\nprobe_limits.per_minute = 1\n", $this->project->read('config/packages/probe_limits.casc'));
    }

    public function testForceOverwritesAFileThatWouldChange(): void
    {
        $this->project->write('config/packages/probe_limits.casc', "#@version = 1.0\n\nprobe_limits.per_minute = 1\n");

        [$status, $output] = $this->project->artisan('cooper:import', ['package' => 'probe_limits', '--force' => true]);

        self::assertSame(0, $status);
        self::assertStringContainsString('overwrote config/packages/probe_limits.casc', $output);
        self::assertStringContainsString('per_minute = 60', $this->project->read('config/packages/probe_limits.casc'));
    }

    public function testAnUnchangedFileIsReportedAsSuch(): void
    {
        $this->project->artisan('cooper:import', ['package' => 'probe_limits']);

        [$status, $output] = $this->project->artisan('cooper:import', ['package' => 'probe_limits']);

        self::assertSame(0, $status);
        self::assertStringContainsString('unchanged config/packages/probe_limits.casc', $output);
    }

    public function testTheApplicationsOwnConfigurationIsImportedWithApp(): void
    {
        $this->project->write('config/reports.php', <<<'PHP'
            <?php

            return [
                'per_page' => env('REPORTS_PER_PAGE', 25),
                'formatter' => fn (array $row) => implode(';', $row),
                'title' => 'Reports for ' . env('APP_NAME', 'Laravel'),
            ];
            PHP);

        [$status, $output] = $this->project->artisan('cooper:import', ['--app' => true]);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('created config/app/reports.casc', $output);
        self::assertStringContainsString('created config/app/database.casc', $output);
        self::assertStringContainsString("reports {\n  per_page = !int(\${REPORTS_PER_PAGE:25})", $this->project->read('config/app/reports.casc'));
        self::assertStringContainsString("skipped:\n", $output);
        self::assertStringContainsString('  reports.formatter: a closure cannot be written as configuration', $output);
        self::assertStringContainsString('import "app/*.casc"', $this->project->read('config/config.casc'));
    }

    public function testAppAndAllTogetherImportThePackagesFirst(): void
    {
        $this->project->artisan('cooper:import', ['--app' => true, '--all' => true]);

        $main = $this->project->read('config/config.casc');
        self::assertLessThan(strpos($main, 'import "app/*.casc"'), strpos($main, 'import "packages/*.casc"'));
    }

    public function testUnderRequiredTheRequiredVariablesAreListed(): void
    {
        $this->project->write('config/reports.php', "<?php return ['key' => env('REPORTS_KEY')];");

        [, $output] = $this->project->artisan('cooper:import', ['--app' => true, '--required' => true]);

        self::assertMatchesRegularExpression('~required now .*:\n(  .*\n)*  REPORTS_KEY\n~', $output);
    }

    public function testUnderRequiredTheMigratedApplicationBootsOnCascOnceEveryVariableIsSet(): void
    {
        // Testbench's skeleton's own config/*.php, as `cooper:import --app
        // --required` converts them; every variable they now require is set.
        [, $output] = $this->project->artisan('cooper:import', ['--app' => true, '--all' => true, '--required' => true]);
        $env = '';
        preg_match_all('~^  ([A-Z_][A-Z0-9_]*)$~m', $output, $m);
        self::assertContains('ASSET_URL', $m[1]);
        foreach ($m[1] as $name) {
            $env .= "{$name}=set-for-the-test\n";
        }
        $this->project->write('.env', "APP_NAME=Migrated\n{$env}");

        $config = $this->project->console()['config'];

        self::assertSame('Migrated', $config->get('app.name'));
        self::assertSame('sqlite', $config->get('database.default'));
        self::assertSame("{$this->project->dir}/database/database.sqlite", $config->get('database.connections.sqlite.database'));
        self::assertSame('probe', $config->get('probe.name'));
    }

    public function testUnderRequiredTheApplicationDoesNotBootUntilEveryRequiredVariableIsSet(): void
    {
        $this->project->artisan('cooper:import', ['--app' => true, '--required' => true]);

        $this->expectException(\JOetjen\CooperConfig\ConfigError::class);
        $this->expectExceptionMessage('undefined reference ${ASSET_URL}');

        $this->project->console();
    }

    public function testAVariableWithoutADefaultKeepsEnvsNullByDefault(): void
    {
        $this->project->write('config/reports.php', "<?php return ['key' => env('REPORTS_KEY'), 'url' => env('REPORTS_URL') . '/x'];");

        [, $output] = $this->project->artisan('cooper:import', ['--app' => true]);

        self::assertStringContainsString("  key = \${REPORTS_KEY:nil}\n", $this->project->read('config/app/reports.casc'));
        // Inside a string, PHP's null is the empty string.
        self::assertStringContainsString("  url = \"\${REPORTS_URL:''}/x\"", $this->project->read('config/app/reports.casc'));
        self::assertStringNotContainsString('required now', $output);
    }

    public function testUnderRequiredAVariableWithoutADefaultIsWrittenStrict(): void
    {
        $this->project->write('config/reports.php', "<?php return ['key' => env('REPORTS_KEY'), 'url' => env('REPORTS_URL') . '/x'];");

        $this->project->artisan('cooper:import', ['--app' => true, '--required' => true]);

        self::assertStringContainsString("  key = \${REPORTS_KEY}\n", $this->project->read('config/app/reports.casc'));
        self::assertStringContainsString("  url = \"\${REPORTS_URL}/x\"", $this->project->read('config/app/reports.casc'));
    }

    public function testTheMigratedApplicationBootsWithNoVariablesSet(): void
    {
        [$status, $output] = $this->project->artisan('cooper:import', ['--app' => true, '--all' => true]);
        self::assertSame(0, $status, $output);

        $config = $this->project->console()['config'];

        self::assertNull($config->get('app.asset_url'));
        self::assertSame('Laravel', $config->get('app.name'));
    }
}
