<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Import;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Where the PHP configuration `cooper:import` translates is: the
 * application's own `config/*.php`, and each package's defaults.
 *
 * A package's configuration is found two ways, as Laravel itself has it:
 *
 *  - **published** -- `$this->publishes([... => config_path('x.php')],
 *    'config')` in a provider's `boot()`, which Laravel records; the
 *    target's name under `config/` is the configuration's name;
 *  - **merged** -- `$this->mergeConfigFrom(__DIR__ . '/../config/x.php',
 *    'x')` (or `replaceConfigRecursivelyFrom()`) in a provider's
 *    `register()`, which Laravel does not record.
 *    The providers' source is parsed for the call (never run), and its
 *    path worked out from `__DIR__`, `__FILE__`, string literals, `.`,
 *    `dirname()` and `realpath()` -- the ways packages write it.
 *
 * Every provider the application registered is looked at, deferred ones
 * included.
 *
 * @internal
 */
final class ConfigSources
{
    private function __construct()
    {
    }

    /**
     * The packages' configuration files.
     *
     * @return array<string, string> configuration name => file, by name
     */
    public static function packages(Application $app): array
    {
        $found = [];
        foreach (self::providers($app) as $provider) {
            $found += self::mergedBy($provider);
        }
        $configPath = rtrim($app->configPath(), '/') . '/';
        foreach (ServiceProvider::pathsToPublish(null, 'config') as $from => $to) {
            foreach (self::phpFiles((string) $from, (string) $to) as $file => $target) {
                if (str_starts_with($target, $configPath)) {
                    $found += [self::name(substr($target, strlen($configPath))) => $file];
                }
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * The application's own `config/*.php`, nested directories included
     * (`config/services/mail.php` is `services.mail`, as Laravel names it).
     *
     * @return array<string, string> configuration name => file, by name
     */
    public static function application(Application $app): array
    {
        $dir = realpath($app->configPath());
        if ($dir === false) {
            return [];
        }
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[self::name(substr($file->getPathname(), strlen($dir) + 1))] = $file->getPathname();
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * A file's path under `config/` as a configuration name:
     * `services/mail.php` is `services.mail`.
     */
    private static function name(string $relative): string
    {
        return str_replace(['/', '\\'], '.', substr($relative, 0, -strlen('.php')));
    }

    /**
     * Every provider class the application knows of.
     *
     * @return list<class-string>
     */
    private static function providers(Application $app): array
    {
        $providers = array_keys($app->getLoadedProviders());
        foreach ($app->getDeferredServices() as $provider) {
            $providers[] = $provider;
        }
        $classes = [];
        foreach (array_unique($providers) as $provider) {
            if (class_exists($provider)) {
                $classes[] = $provider;
            }
        }

        return $classes;
    }

    /**
     * The `mergeConfigFrom()`/`replaceConfigRecursivelyFrom()` calls in
     * `$provider`'s source whose path
     * and key can be worked out without running anything.
     *
     * @param class-string $provider
     * @return array<string, string> configuration name => file
     */
    private static function mergedBy(string $provider): array
    {
        $file = (new \ReflectionClass($provider))->getFileName();
        if ($file === false || !is_file($file)) {
            return [];
        }
        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($file)) ?? [];
        } catch (\PhpParser\Error) {
            return [];
        }

        $found = [];
        $calls = (new NodeFinder())->find($statements, static fn (Node $node): bool => $node instanceof Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toLowerString(), ['mergeconfigfrom', 'replaceconfigrecursivelyfrom'], true));
        foreach ($calls as $call) {
            assert($call instanceof Expr\MethodCall);
            if ($call->isFirstClassCallable() || count($call->args) !== 2) {
                continue;
            }
            [$path, $key] = $call->getArgs();
            $resolved = self::staticPath($path->value, $file);
            if ($key->value instanceof Scalar\String_ && $resolved !== null && is_file($resolved)) {
                $found[$key->value->value] = (string) realpath($resolved);
            }
        }

        return $found;
    }

    /**
     * A path expression's value, worked out from the source alone, or
     * `null` when it needs anything run.
     */
    private static function staticPath(Expr $expr, string $file): ?string
    {
        return match (true) {
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\MagicConst\Dir => dirname($file),
            $expr instanceof Scalar\MagicConst\File => $file,
            $expr instanceof Expr\BinaryOp\Concat => self::concatenated($expr, $file),
            $expr instanceof Expr\FuncCall => self::pathCall($expr, $file),
            default => null,
        };
    }

    private static function concatenated(Expr\BinaryOp\Concat $concat, string $file): ?string
    {
        $left = self::staticPath($concat->left, $file);
        $right = self::staticPath($concat->right, $file);

        return $left === null || $right === null ? null : $left . $right;
    }

    /**
     * `dirname(path[, levels])` or `realpath(path)`.
     */
    private static function pathCall(Expr\FuncCall $call, string $file): ?string
    {
        if (!$call->name instanceof Node\Name || $call->isFirstClassCallable()) {
            return null;
        }
        $args = $call->getArgs();
        $inner = isset($args[0]) ? self::staticPath($args[0]->value, $file) : null;
        if ($inner === null) {
            return null;
        }

        return match ($call->name->toLowerString()) {
            'dirname' => match (true) {
                !isset($args[1]) => dirname($inner),
                $args[1]->value instanceof Scalar\Int_ && $args[1]->value->value >= 1 => dirname($inner, $args[1]->value->value),
                default => null,
            },
            'realpath' => realpath($inner) ?: null,
            default => null,
        };
    }

    /**
     * The PHP files a `publishes()` entry copies: the file itself, or
     * every `.php` file of a directory, each with where it would go.
     *
     * @return array<string, string> source file => target path
     */
    private static function phpFiles(string $from, string $to): array
    {
        if (is_file($from)) {
            return str_ends_with($from, '.php') ? [(string) realpath($from) => $to] : [];
        }
        if (!is_dir($from)) {
            return [];
        }
        $files = [];
        $from = (string) realpath($from);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = rtrim($to, '/') . substr($file->getPathname(), strlen($from));
            }
        }

        return $files;
    }
}
