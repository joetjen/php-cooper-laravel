<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests;

use JOetjen\CooperLaravel\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * `php artisan config:cache` keeps working: it caches what CASC
 * produced, and a cached configuration is used as Laravel uses it,
 * without CASC being read.
 */
#[RunTestsInSeparateProcesses]
final class ConfigCacheTest extends IntegrationTestCase
{
    public function testConfigCacheWritesWhatCascProduced(): void
    {
        $this->project->write('.env', "COOPER_LARAVEL_PROBE=from-dotenv\n");
        $this->project->casc(<<<'CASC'
            app {
              name    = ${COOPER_LARAVEL_PROBE}
              upload  = 2MiB
              storage = !storage_path("uploads")
              derived = !php("""strtoupper('abc')""")
            }
            CASC);

        [$status] = $this->project->artisan('config:cache');
        self::assertSame(0, $status);

        $cached = require "{$this->project->dir}/bootstrap/cache/config.php";
        self::assertSame('from-dotenv', $cached['app']['name']);
        self::assertSame(2097152, $cached['app']['upload']);
        self::assertSame("{$this->project->dir}/storage/uploads", $cached['app']['storage']);
        self::assertSame('ABC', $cached['app']['derived']);
        self::assertSame('probe', $cached['probe']['name']);
    }

    public function testACachedConfigurationIsUsedWithoutReadingCasc(): void
    {
        $this->project->casc('app { name = "before caching" }');
        [$status] = $this->project->artisan('config:cache');
        self::assertSame(0, $status);

        // Were CASC read now, this would fail the boot.
        $this->project->casc('app { name = ${COOPER_LARAVEL_SURELY_UNSET} }');
        $app = $this->project->console();

        self::assertTrue($app->configurationIsCached());
        self::assertSame('before caching', $app['config']->get('app.name'));
    }

    public function testConfigClearGoesBackToCasc(): void
    {
        $this->project->casc('app { name = "before caching" }');
        $this->project->artisan('config:cache');
        $this->project->casc('app { name = "after clearing" }');

        [$status] = $this->project->artisan('config:clear');

        self::assertSame(0, $status);
        self::assertSame('after clearing', $this->project->console()['config']->get('app.name'));
    }
}
