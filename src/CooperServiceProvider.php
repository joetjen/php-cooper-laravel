<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel;

use Illuminate\Foundation\Bootstrap\LoadConfiguration as LaravelLoadConfiguration;
use Illuminate\Support\ServiceProvider;
use JOetjen\CooperConfig\Command\CheckCommand;
use JOetjen\CooperConfig\Command\InitCommand;
use JOetjen\CooperLaravel\Bootstrap\LoadConfiguration;
use JOetjen\CooperLaravel\Command\ImportCommand;

/**
 * Cooper's commands on artisan, rooted at the application's base path:
 *
 *  - `cooper:init`, `cooper:check` -- php-cooper-config's, unchanged;
 *    `cooper:check` loads the document the way the application does,
 *    with this integration's tags and the options `bootstrap/app.php`
 *    gave `LoadConfiguration`;
 *  - `cooper:import` -- this package's: packages' and the application's
 *    PHP configuration into CASC.
 *
 * Loading the configuration is not the provider's: providers are
 * registered after the configuration is loaded, so the bootstrappers
 * bound in `bootstrap/app.php` do it. Laravel discovers this provider
 * from `composer.json`.
 */
class CooperServiceProvider extends ServiceProvider
{
    /**
     * Binds the commands, each built for this application.
     */
    public function register(): void
    {
        $this->app->bind(InitCommand::class, fn (): InitCommand => new InitCommand($this->app->basePath()));
        $this->app->bind(CheckCommand::class, fn (): CheckCommand => new CheckCommand($this->app->basePath(), $this->checkOptions()));
        $this->app->bind(ImportCommand::class, fn (): ImportCommand => new ImportCommand(LaravelApplication::of($this->app)));
    }

    /**
     * Puts the commands on artisan.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Not php-cooper-config's `cooper:cache:clear`: it clears a
            // compiled cache this integration never reads -- Laravel's own
            // `config:cache` is the cache here, and `config:clear` clears it.
            $this->commands([InitCommand::class, CheckCommand::class, ImportCommand::class]);
        }
    }

    /**
     * What `cooper:check` loads with: the options the application's
     * `LoadConfiguration` loads with, and its document -- or, when the
     * application has not installed it, this integration's tags alone.
     *
     * @return array<string, mixed>
     */
    private function checkOptions(): array
    {
        $loader = $this->app->make(LaravelLoadConfiguration::class);
        if (!$loader instanceof LoadConfiguration) {
            return ['tags' => Tags::for(LaravelApplication::of($this->app))];
        }

        return ['path' => $loader->document($this->app)] + $loader->loadOptions($this->app);
    }
}
