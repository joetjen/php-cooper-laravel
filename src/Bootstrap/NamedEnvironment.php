<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Bootstrap;

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * The environment an artisan command is told to run in with `--env`,
 * as Cooper's override layer.
 *
 * Laravel lets `php artisan --env=production ...` name the environment
 * whatever `APP_ENV` says. Handed to Cooper as `APP_ENV` -- not as
 * `COOPER_ENV` -- it goes through the same mapping as every `APP_ENV`
 * (`production` reads `.env.prod` and `config/prod/`), and a real
 * `COOPER_ENV`, which whoever set it chose, still wins.
 *
 * @internal
 */
final class NamedEnvironment
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options Cooper's options so far
     * @return array<string, mixed> `$options`, with `APP_ENV` in the `env` layer when `--env` names one
     */
    public static function apply(Application $app, array $options): array
    {
        $named = self::named($app);
        if ($named === null) {
            return $options;
        }
        $env = is_array($options['env'] ?? null) ? $options['env'] : [];
        $options['env'] = ['APP_ENV' => $named] + $env;

        return $options;
    }

    private static function named(Application $app): ?string
    {
        if (!$app->runningInConsole() || !isset($_SERVER['argv'])) {
            return null;
        }
        $input = new ArgvInput();
        if (!$input->hasParameterOption('--env')) {
            return null;
        }
        $named = $input->getParameterOption('--env');

        return is_string($named) && $named !== '' ? $named : null;
    }
}
