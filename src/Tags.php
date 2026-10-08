<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel;

use Illuminate\Foundation\Application;
use JOetjen\Cooper\LiteralTag;

/**
 * The tags every load of the application's configuration has, each named
 * exactly as the PHP it stands for, so a `config/*.php` value reads the
 * same in CASC:
 *
 * | Tag | Gives |
 * |---|---|
 * | `!base_path("x")`, `!app_path`, `!config_path`, `!database_path`, `!lang_path`, `!public_path`, `!resource_path`, `!storage_path` | the helper's path, on the machine loading the document; `""` for the directory itself |
 * | `!php_const("PDO::MYSQL_ATTR_SSL_CA")` | a class constant's, an enum case's or a global constant's value |
 * | `!php("""...""")` | the value of the PHP expression, evaluated at load with `$app` in scope |
 *
 * `!php` is a `LiteralTag`: its argument must be a string literal written
 * in the document -- `"..."`, `'...'` or `"""..."""` with nothing
 * interpolated -- or the load is refused, naming the key and the file.
 * No value from the environment, a resolver, a variable or another tag
 * can ever become code. Its code is one expression (`return` is implied);
 * more than one statement needs an immediately invoked closure,
 * `(function () { ...; return $x; })()`. An exception it throws, a parse
 * error included, fails the load naming the key and the file.
 *
 * The path helpers and `!php_const` take any string, from anywhere: a
 * path or a constant's name is data, not code.
 */
final class Tags
{
    /** The path helpers, each a tag of the same name. */
    private const PATH_HELPERS = ['base_path', 'app_path', 'config_path', 'database_path', 'lang_path', 'public_path', 'resource_path', 'storage_path'];

    private function __construct()
    {
    }

    /**
     * The tags, for `$app`.
     *
     * @param Application $app the application whose paths the helpers give, and which `!php` code sees as `$app`
     * @return array<string, callable(mixed): mixed> tag name => tag, as Cooper's `tags` option takes them
     */
    public static function for(Application $app): array
    {
        $tags = [];
        foreach (self::PATH_HELPERS as $name) {
            $tags[$name] = static fn (mixed $path): string => self::path($app, $name, $path);
        }
        $tags['php_const'] = static fn (mixed $name): mixed => self::constant($name);
        $tags['php'] = new LiteralTag(static fn (string $code): mixed => self::run($code, $app));

        return $tags;
    }

    /**
     * The path helpers' names, each a tag's.
     *
     * @return list<string>
     */
    public static function pathHelpers(): array
    {
        return self::PATH_HELPERS;
    }

    /**
     * The path helper `$name`'s path for `$path`, as the global helper
     * gives it (`storage_path()` is `app()->storagePath()`).
     *
     * @throws \InvalidArgumentException when `$path` is not a string
     */
    private static function path(Application $app, string $name, mixed $path): string
    {
        if (!is_string($path)) {
            throw new \InvalidArgumentException("!{$name} takes a path, a string; got " . get_debug_type($path) . " (write !{$name}(\"\") for the directory itself)");
        }

        return match ($name) {
            'base_path' => $app->basePath($path),
            'app_path' => $app->path($path),
            'config_path' => $app->configPath($path),
            'database_path' => $app->databasePath($path),
            'lang_path' => $app->langPath($path),
            'public_path' => $app->publicPath($path),
            'resource_path' => $app->resourcePath($path),
            default => $app->storagePath($path),
        };
    }

    /**
     * @throws \InvalidArgumentException when `$name` is not a string naming a defined constant
     */
    private static function constant(mixed $name): mixed
    {
        if (!is_string($name)) {
            throw new \InvalidArgumentException('!php_const takes a constant\'s name, a string; got ' . get_debug_type($name));
        }
        $name = ltrim(trim($name), '\\');
        if (!defined($name)) {
            throw new \InvalidArgumentException("no constant {$name} is defined");
        }

        return constant($name);
    }

    /**
     * Evaluates `$code`, one PHP expression, with `$app` in scope.
     *
     * It runs in a static closure of its own, so the only variable it
     * sees is `$app` -- not this class's, and not the loader's.
     */
    private static function run(string $code, Application $app): mixed
    {
        $evaluate = static function (Application $app, string $__cooperCode): mixed {
            return eval('return ' . $__cooperCode . ';');
        };

        return $evaluate($app, $code);
    }
}
