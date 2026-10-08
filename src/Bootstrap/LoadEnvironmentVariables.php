<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Bootstrap;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables as LaravelLoadEnvironmentVariables;
use JOetjen\Cooper\Dotenv;
use JOetjen\CooperLaravel\LaravelApplication;

/**
 * Laravel's `.env` loading, done by Cooper: bound in `bootstrap/app.php`
 * in place of `Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables`
 * (see README.md), which both kernels resolve from the container.
 *
 * Cooper's `Dotenv::export()` puts what the files say into the process
 * environment, so `env()`, `$_ENV`, `$_SERVER` and `getenv()` -- which
 * Laravel and its packages read -- find it. That is the only reason it
 * is exported: the CASC document reads the same files by itself. One
 * loader, one set of rules, so the two never disagree:
 *
 *  1. `.env`
 *  2. `.env.<COOPER_ENV>` -- `.env.dev`, `.env.test`, `.env.prod`;
 *     `COOPER_ENV` falls back to `APP_ENV`, `local` read as `dev`,
 *     `testing` as `test`, `production` as `prod`;
 *  3. `.env.local`;
 *  4. the real environment, which always wins: a variable it already
 *     has is never overwritten.
 *
 * `php artisan --env=X` counts as `APP_ENV=X` (see `NamedEnvironment`).
 * The files are read from `$app->environmentPath()`. A cached
 * configuration reads none, as in Laravel.
 */
class LoadEnvironmentVariables extends LaravelLoadEnvironmentVariables
{
    /**
     * @param array<string, mixed> $options Cooper's `.env` options (`dotenv`, `dotenvEnv`, `dotenvFiles`, `dotenvDir`, `dotenvOverride`, `env`); give `LoadConfiguration` the same ones
     */
    public function __construct(private readonly array $options = [])
    {
    }

    /**
     * Exports the `.env` files, unless the configuration is cached.
     *
     * @param Application $app the application being bootstrapped
     * @throws \JOetjen\Cooper\CooperError (stage `dotenv`) on a malformed `.env` file
     * @throws \LogicException when `$app` is not Laravel's application
     */
    public function bootstrap(Application $app): void
    {
        $app = LaravelApplication::of($app);
        if ($app->configurationIsCached()) {
            return;
        }

        Dotenv::export(NamedEnvironment::apply($app, $this->options + ['dotenvDir' => $app->environmentPath()]));
    }
}
