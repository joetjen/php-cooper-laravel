<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Support\Fixtures\ProbePackage;

use Illuminate\Support\ServiceProvider;

/**
 * A package as Laravel packages are written: its defaults merged with
 * `mergeConfigFrom()` -- `probe` published for the application to copy,
 * `probe_limits` merged only, reached through `dirname(__DIR__)` -- and
 * `probe_queue` by `replaceConfigRecursivelyFrom()` through `realpath()`, for
 * `cooper:import` to find and for CASC to sit on top of.
 */
final class ProbeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/probe.php', 'probe');
        $this->mergeConfigFrom(dirname(__DIR__) . '/ProbePackage/config/probe_limits.php', 'probe_limits');
        $this->replaceConfigRecursivelyFrom(realpath(__DIR__ . '/config/probe_queue.php'), 'probe_queue');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/config/probe.php' => config_path('probe.php')], 'config');
    }
}
