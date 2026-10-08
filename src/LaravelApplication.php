<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;

/**
 * Laravel's application, from the contract its bootstrappers and
 * providers are handed.
 *
 * What Cooper needs -- the environment path, whether the configuration
 * is cached, `app_path()`'s directory -- is the application's, not the
 * contract's, and Laravel has one application. Another implementation
 * of the contract is refused by name rather than half worked with.
 *
 * @internal
 */
final class LaravelApplication
{
    private function __construct()
    {
    }

    /**
     * @throws \LogicException when `$app` is not Laravel's application
     */
    public static function of(ApplicationContract $app): Application
    {
        if (!$app instanceof Application) {
            throw new \LogicException('joetjen/cooper-laravel works with ' . Application::class . ', not ' . $app::class);
        }

        return $app;
    }
}
