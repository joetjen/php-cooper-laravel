<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Bootstrap;

use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration as LaravelLoadConfiguration;
use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\CooperConfig\ConfigError;
use JOetjen\CooperConfig\ConversionError;
use JOetjen\CooperLaravel\ConfigValues;
use JOetjen\CooperLaravel\LaravelApplication;
use JOetjen\CooperLaravel\Tags;

/**
 * Laravel's configuration, from CASC: bound in `bootstrap/app.php` in
 * place of `Illuminate\Foundation\Bootstrap\LoadConfiguration` (see
 * README.md), which both kernels resolve from the container.
 *
 * ## What it reads
 *
 * `config/config.casc` -- and whatever it imports -- instead of
 * `config/*.php`, which are not read at all. Each top-level block is one
 * configuration "file": `database { connections.mysql.host = "..." }`
 * is `config('database.connections.mysql.host')`, as
 * `config/database.php` was.
 *
 * Underneath it, exactly as under the PHP files it replaces:
 *
 *  - **the framework's defaults** (`vendor/laravel/framework/config/`),
 *    merged with Laravel's own rule -- a block replaces the default's
 *    top-level keys it sets, and `database.connections`, `cache.stores`,
 *    `logging.channels` and the other lists of named things are merged
 *    one level deeper, so a connection added keeps the default ones;
 *  - **the packages' defaults**, which their providers merge in later
 *    with `mergeConfigFrom()`, under whatever the document set.
 *
 * Values arrive as plain PHP values (see `ConfigValues`): a byte size in
 * bytes, a duration in milliseconds, an address as text, a secret
 * revealed unless its block's `cooper-secrets` says otherwise.
 *
 * ## The load
 *
 * Every load has this integration's tags (see `Tags`): the path helpers,
 * `!php_const` and `!php`. An application's own tag of the same name
 * wins. `COOPER_ENV` -- which `import "${COOPER_ENV}/*.casc"` reads --
 * comes from the environment as `LoadEnvironmentVariables` left it, so
 * Laravel's `APP_ENV=local`/`testing`/`production` read `config/dev/`,
 * `config/test/`, `config/prod/`; `php artisan --env=X` counts as
 * `APP_ENV=X`.
 *
 * A missing document configures nothing beyond those defaults -- a
 * fresh application has none yet, and `php artisan cooper:init` must be
 * able to run. A document that does not load stops the boot with a
 * `ConfigError` naming it.
 *
 * ## `config:cache`
 *
 * A cached configuration is used as Laravel uses it: this class only
 * replaces how the files are read, so with `bootstrap/cache/config.php`
 * present CASC is not read at all. `php artisan config:cache` caches
 * what the document produced, `!php` results and path helpers as
 * evaluated on the machine running it.
 */
class LoadConfiguration extends LaravelLoadConfiguration
{
    /**
     * @param array<string, mixed> $options Cooper's load options (`resolvers`, `tags`, `modules`, `importSchemes`, `env`, `.env` options); `cache` defaults to off
     * @param string|null $document the document's path; `null` for `config/config.casc` under the application's config path
     */
    public function __construct(private readonly array $options = [], private readonly ?string $document = null)
    {
    }

    /**
     * The document this loader reads, for `$app`.
     *
     * @param Application $app the application
     * @return string an absolute path
     * @throws \LogicException when `$app` is not Laravel's application
     */
    public function document(Application $app): string
    {
        return $this->document ?? $app->configPath('config.casc');
    }

    /**
     * Cooper's options for a load of the document in `$app`: the
     * application's own, this integration's tags under them, `.env`
     * files from the environment path, `--env` as `APP_ENV`, and no
     * in-memory cache.
     *
     * The cache is off because it pays only where one process loads the
     * document more than once, and Laravel's process loads it once -- per
     * request, or once for a worker -- while `config:cache` is Laravel's
     * own, better answer.
     *
     * @param Application $app the application the tags work for
     * @return array<string, mixed>
     * @throws \LogicException when `$app` is not Laravel's application
     */
    public function loadOptions(Application $app): array
    {
        $app = LaravelApplication::of($app);
        $options = $this->options;
        $tags = is_array($options['tags'] ?? null) ? $options['tags'] : [];
        $options['tags'] = $tags + Tags::for($app);
        $options += ['dotenvDir' => $app->environmentPath(), 'cache' => false];

        return NamedEnvironment::apply($app, $options);
    }

    /**
     * Fills `$repository` from the document, over the framework's
     * defaults -- in place of Laravel's reading of `config/*.php`.
     *
     * @param Application $app the application being bootstrapped
     * @param RepositoryContract $repository its configuration
     * @throws ConfigError when the document does not load, or a value cannot be converted
     * @throws \LogicException when `$app` is not Laravel's application
     */
    protected function loadConfigurationFiles(Application $app, RepositoryContract $repository): void
    {
        $blocks = $this->blocks($app);

        $shouldMerge = method_exists($app, 'shouldMergeFrameworkConfiguration')
            ? $app->shouldMergeFrameworkConfiguration()
            : true;
        $base = $shouldMerge ? $this->getBaseConfiguration() : [];

        foreach ($blocks as $name => $config) {
            $name = (string) $name;
            if (isset($base[$name]) && is_array($base[$name]) && is_array($config)) {
                $config = $this->overDefaults($name, $base[$name], $config);
            }
            unset($base[$name]);
            $repository->set($name, $config);
        }
        foreach ($base as $name => $config) {
            $repository->set((string) $name, $config);
        }
    }

    /**
     * The document's top-level blocks, as configuration values; none
     * when there is no document.
     *
     * @return array<array-key, mixed>
     * @throws ConfigError
     */
    private function blocks(Application $app): array
    {
        $file = $this->document($app);
        if (!is_file($file)) {
            return [];
        }
        $options = $this->loadOptions($app);

        try {
            $trace = Cooper::loadFileTraced($file, $options);
        } catch (CooperError $e) {
            throw ConfigError::fromCooper($file, $e);
        }
        try {
            $modules = is_array($options['modules'] ?? null) ? $options['modules'] : [];

            return ConfigValues::fromDocument($trace->config, $modules);
        } catch (ConversionError $e) {
            throw ConfigError::fromConversion($file, $e);
        }
    }

    /**
     * `$config` over the framework's `$defaults` for `$name`, by the rule
     * Laravel applies to a `config/<name>.php` -- so moving a file into
     * CASC changes nothing about what it overrides.
     *
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $config
     * @return array<array-key, mixed>
     */
    private function overDefaults(string $name, array $defaults, array $config): array
    {
        $merged = array_merge($defaults, $config);
        foreach ($this->mergeableOptions($name) as $option) {
            if (isset($config[$option], $defaults[$option]) && is_array($config[$option]) && is_array($defaults[$option])) {
                $merged[$option] = array_merge($defaults[$option], $config[$option]);
            }
        }

        return $merged;
    }
}
