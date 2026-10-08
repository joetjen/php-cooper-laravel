<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests;

use Illuminate\Contracts\Console\Kernel;
use JOetjen\CooperLaravel\CooperServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * The provider puts Cooper's commands on artisan -- here in Testbench's
 * own application, as a package's tests see one.
 */
final class CooperServiceProviderTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CooperServiceProvider::class];
    }

    public function testEveryCooperCommandIsOnArtisan(): void
    {
        $commands = array_keys($this->app->make(Kernel::class)->all());

        foreach (['cooper:init', 'cooper:check', 'cooper:import'] as $command) {
            self::assertContains($command, $commands);
        }
    }

    public function testCooperCacheClearIsNotOnArtisan(): void
    {
        // Laravel's `config:cache` is the cache here; php-cooper-config's
        // compiled cache, which `cooper:cache:clear` clears, is never read.
        self::assertNotContains('cooper:cache:clear', array_keys($this->app->make(Kernel::class)->all()));
    }

    public function testTheCommandsAreRootedAtTheApplicationsBasePath(): void
    {
        $this->artisan('cooper:check')
            ->expectsOutputToContain(base_path('config/config.casc'))
            ->assertExitCode(0);
    }
}
