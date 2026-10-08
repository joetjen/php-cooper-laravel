<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Command;

use Illuminate\Foundation\Application;
use JOetjen\CooperConfig\Casc\MapValue;
use JOetjen\CooperConfig\Casc\Writer;
use JOetjen\CooperLaravel\Import\ConfigSources;
use JOetjen\CooperLaravel\Import\LineDiff;
use JOetjen\CooperLaravel\Import\PhpConfigTranslator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cooper:import <package> | --all | --app [--required] [--force]`: PHP
 * configuration moved into CASC.
 *
 *  - `<package>` -- one package's configuration, by its name (`sanctum`
 *    for `config('sanctum.*')`), into `config/packages/<name>.casc`;
 *  - `--all` -- every package's configuration found (see
 *    `ConfigSources`);
 *  - `--app` -- the application's own `config/*.php`, a one-time
 *    migration, into `config/app/<name>.casc`.
 *
 * The PHP is parsed, never run (see `PhpConfigTranslator`), and the
 * CASC written with php-cooper-config's `Writer`. `config/config.casc`
 * is made to `import "packages/*.casc"` and `import "app/*.casc"` --
 * once each, packages first, and both before the environment's overlay,
 * so the application's settings win over the packages' and an
 * environment's over both. A missing document is created.
 *
 * `env('X')` without a default is written as `${X:nil}` -- an empty
 * string inside a concatenation, a cast's own zero value under a cast --
 * which reads an unset variable as `env()` did: the faithful
 * translation. `--required` writes the strict `${X}` instead, which
 * fails the load while `X` is unset; since every artisan command loads
 * the configuration, so does artisan.
 *
 * The command ends with a summary: how many keys were written exactly,
 * which went through `!php`, which were left out, and -- under
 * `--required` -- which variables are now required.
 *
 * A file already there is left alone when it would not change, and not
 * overwritten without `--force` when it would: the difference is shown,
 * and the command fails. The PHP files are never touched -- deleting
 * the application's is its own step, once the CASC says what it should.
 */
final class ImportCommand extends Command
{
    private const MAIN_DOCUMENT = 'config/config.casc';

    /** Each kind of import's directory under `config/`, in the order the document imports them. */
    private const DIRECTORIES = ['package' => 'packages', 'app' => 'app'];

    /** The environments a created document gets an overlay directory for, as `cooper:init` does. */
    private const ENVIRONMENTS = ['dev', 'test', 'prod'];

    /**
     * @param Application $app the application, whose providers name the packages' configuration and whose paths say where things go
     */
    public function __construct(private readonly Application $app)
    {
        parent::__construct('cooper:import');
    }

    protected function configure(): void
    {
        $this->setDescription('Convert PHP configuration into CASC: a package\'s, every package\'s (--all), or the application\'s own (--app)');
        $this->addArgument('package', InputArgument::OPTIONAL, 'The package configuration\'s name, as config() reads it (sanctum, horizon, ...)');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Import every package\'s configuration');
        $this->addOption('app', null, InputOption::VALUE_NONE, 'Import the application\'s own config/*.php');
        $this->addOption('required', null, InputOption::VALUE_NONE, 'Write env(\'X\') without a default as ${X}, which fails the load while X is unset, instead of ${X:nil}, null as env() gave');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite a CASC file that would change');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        $all = (bool) $input->getOption('all');
        $app = (bool) $input->getOption('app');
        if (!is_string($package) && !$all && !$app) {
            self::say($output, 'name a package\'s configuration to import, or pass --all for every package\'s, --app for the application\'s own config/*.php');

            return self::FAILURE;
        }

        $packages = ConfigSources::packages($this->app);
        $sources = [];
        if (is_string($package)) {
            if (!isset($packages[$package])) {
                self::say($output, "no package configuration named \"{$package}\"; there is: " . ($packages === [] ? '(none)' : implode(', ', array_keys($packages))));

                return self::FAILURE;
            }
            $sources['package'] = [$package => $packages[$package]];
        }
        if ($all) {
            $sources['package'] = $packages;
        }
        if ($app) {
            $sources['app'] = ConfigSources::application($this->app);
        }

        $translator = new PhpConfigTranslator((bool) $input->getOption('required'));
        $files = [];
        $exact = 0;
        $viaPhp = $skipped = $required = [];
        try {
            foreach (self::DIRECTORIES as $kind => $directory) {
                foreach ($sources[$kind] ?? [] as $name => $file) {
                    $translation = $translator->translate((string) file_get_contents($file), $file, $name);
                    $files["config/{$directory}/{$name}.casc"] = (new Writer())->write(
                        self::nested(explode('.', $name), $translation->tree),
                        "Imported from {$this->shown($file)} by php artisan cooper:import.",
                    );
                    $exact += $translation->exact;
                    array_push($viaPhp, ...$translation->viaPhp);
                    array_push($skipped, ...$translation->skipped);
                    array_push($required, ...$translation->required);
                }
            }
        } catch (\InvalidArgumentException $e) {
            self::say($output, $e->getMessage());

            return self::FAILURE;
        }

        $ok = true;
        foreach ($files as $path => $content) {
            $ok = $this->write($path, $content, (bool) $input->getOption('force'), $output) && $ok;
        }
        $this->ensureMainImports(array_keys(array_filter($sources)), $output);
        self::summary($output, $exact, $viaPhp, $skipped, array_values(array_unique($required)));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `$tree` under the configuration name's segments: `services.mail`
     * is `services { mail { ... } }`.
     *
     * @param non-empty-list<string> $segments
     * @param array<array-key, mixed> $tree
     * @return array<string, mixed>
     */
    private static function nested(array $segments, array $tree): array
    {
        $value = $tree === [] || array_is_list($tree) ? new MapValue($tree) : $tree;
        foreach (array_reverse($segments) as $segment) {
            $value = [$segment => $value];
        }

        return $value;
    }

    /**
     * `$file` relative to the application, where it is inside it.
     */
    private function shown(string $file): string
    {
        $base = rtrim($this->app->basePath(), '/') . '/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Writes one file -- unless it would not change, or would and
     * `$force` is off.
     *
     * @return bool false when a file was left alone because it would change
     */
    private function write(string $path, string $content, bool $force, OutputInterface $output): bool
    {
        $file = $this->app->basePath($path);
        $existing = is_file($file) ? (string) file_get_contents($file) : null;
        if ($existing === $content) {
            self::say($output, "unchanged {$path}");

            return true;
        }
        if ($existing !== null && !$force) {
            self::say($output, "not overwriting {$path} -- it differs from the import (--force overwrites it):");
            self::say($output, rtrim(LineDiff::between($existing, $content, "{$path} (on disk)", "{$path} (imported)"), "\n"));

            return false;
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        self::say($output, ($existing === null ? 'created ' : 'overwrote ') . $path);

        return true;
    }

    /**
     * Makes `config/config.casc` import each directory written to --
     * once, packages before the application, both before the
     * environment's overlay. A missing document is created with them.
     *
     * @param list<string> $kinds the kinds imported (`package`, `app`)
     */
    private function ensureMainImports(array $kinds, OutputInterface $output): void
    {
        $imports = [];
        foreach (self::DIRECTORIES as $kind => $directory) {
            if (in_array($kind, $kinds, true) && (glob($this->app->basePath("config/{$directory}/*.casc")) ?: []) !== []) {
                $imports[] = "import \"{$directory}/*.casc\"";
            }
        }

        $file = $this->app->basePath(self::MAIN_DOCUMENT);
        if (!is_file($file)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, self::mainDocument($imports));
            self::say($output, 'created ' . self::MAIN_DOCUMENT);
            // A glob import matching no file is an error (CASC.md §5.1), so
            // each environment the document imports needs one.
            foreach (self::ENVIRONMENTS as $env) {
                if ((glob($this->app->basePath("config/{$env}/*.casc")) ?: []) === []) {
                    $this->write("config/{$env}/app.casc", "#@version = 1.0\n\n# The {$env} environment's settings, over config/config.casc.\n# Every .casc file in this directory is imported.\n", false, $output);
                }
            }

            return;
        }

        $document = (string) file_get_contents($file);
        foreach ($imports as $import) {
            $pattern = '~^[ \t]*' . preg_quote($import, '~') . '~m';
            if (preg_match($pattern, str_replace("'", '"', $document)) === 1) {
                continue;
            }
            $document = self::insertBeforeLaterImports($document, $import);
            self::say($output, "added {$import} to " . self::MAIN_DOCUMENT);
        }
        file_put_contents($file, $document);
    }

    /**
     * `$document` with `$import` on a line of its own before the first
     * import it must precede -- the application's, for the packages', and
     * the environment's for both -- else at the end.
     */
    private static function insertBeforeLaterImports(string $document, string $import): string
    {
        $later = str_contains($import, 'packages/')
            ? '(?:app/\*\.casc|[^"\'\n]*\$\{COOPER_ENV\})'
            : '[^"\'\n]*\$\{COOPER_ENV\}';
        if (preg_match('~^[ \t]*import[ \t]+["\']' . $later . '~m', $document, $m, PREG_OFFSET_CAPTURE) === 1) {
            return substr($document, 0, $m[0][1]) . "{$import}\n\n" . substr($document, $m[0][1]);
        }

        return rtrim($document, "\n") . "\n\n{$import}\n";
    }

    /**
     * @param list<string> $imports
     */
    private static function mainDocument(array $imports): string
    {
        $lines = [];
        foreach ($imports as $import) {
            $lines[] = str_contains($import, 'packages/')
                ? "# Each package's settings, one file per package.\n{$import}"
                : "# The application's own settings, one file per config/*.php it replaced.\n{$import}";
        }

        return "#@version = 1.0\n\n"
            . "# The application's configuration: each top-level block is what one\n"
            . "# config/<name>.php held (app { ... } is config('app.*')).\n\n"
            . ($lines === [] ? '' : implode("\n\n", $lines) . "\n\n")
            . "# Last, so an environment overrides everything above.\n"
            . "import \"\${COOPER_ENV}/*.casc\"\n";
    }

    /**
     * The closing account: how many keys were exact, and every one that
     * was not, and every variable now required -- a list only
     * `--required` fills, since the default translation requires none.
     *
     * @param list<string> $viaPhp
     * @param list<string> $skipped
     * @param list<string> $required
     */
    private static function summary(OutputInterface $output, int $exact, array $viaPhp, array $skipped, array $required): void
    {
        self::say($output, '');
        self::say($output, "{$exact} keys exact, " . count($viaPhp) . ' via !php, ' . count($skipped) . ' skipped');
        $sections = [
            'via !php:' => $viaPhp,
            'skipped:' => $skipped,
            'required now (env() without a default, under --required: ${X} fails the load while X is unset -- write ${X:nil} where null was fine):' => $required,
        ];
        foreach ($sections as $heading => $lines) {
            if ($lines === []) {
                continue;
            }
            self::say($output, $heading);
            foreach ($lines as $line) {
                self::say($output, "  {$line}");
            }
        }
    }

    /**
     * Writes one line exactly as given: a path or a value may hold
     * `<...>`, which console markup would read as a style tag.
     */
    private static function say(OutputInterface $output, string $line): void
    {
        $output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
