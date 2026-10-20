# php-cooper-laravel

> [!WARNING]
> **Under heavy development: not for production use before `1.0.0`.**
> Things can and will break, and the specification can and will change,
> between any two releases until `1.0.0`. Do not use this library in a
> production system yet.

The Laravel integration of [Cooper](https://github.com/joetjen/php-cooper). A
[CASC](https://github.com/joetjen/php-cooper/blob/main/casc/CASC.md) document replaces Laravel's
configuration: `config/config.casc` fills the configuration repository,
and `config/*.php` is no longer read. Cooper also reads the `.env`
files.

```casc
#@version = 1.0

# config/config.casc
app {
  name  = ${APP_NAME:"Laravel"}
  debug = !bool(${APP_DEBUG:false})
}

database {
  default = ${DB_CONNECTION:"mysql"}
  connections.mysql.host = ${DB_HOST:"127.0.0.1"}
}

import "packages/*.casc"        # each package's settings
import "app/*.casc"             # what config/*.php held, after cooper:import --app
import "${COOPER_ENV}/*.casc"   # config/dev/, config/test/, config/prod/
```

```php
config('database.connections.mysql.host');   // as before
```

Each top-level block is what one `config/<name>.php` file held. Code
reading configuration does not change: `config('app.name')`,
`Config::get(...)` and every package work as before. The framework's
defaults and each package's `mergeConfigFrom()` defaults still sit
underneath the document. `php artisan config:cache` keeps working.

**Status: 0.2.0, pre-release.** See [CHANGELOG.md](CHANGELOG.md).

## Contents

- [Installation](#installation): manual, step by step
- [Migrating `config/*.php`](#migrating-configphp)
- [The document](#the-document)
- [`.env` files](#env-files)
- [Tags](#tags): the path helpers, `!php_const`, `!php`
- [Commands](#commands), and what `cooper:import` translates
- [`config:cache`](#configcache)
- [Customising](#customising)
- [Limits](#limits)

## Installation

Nothing is patched automatically. Every step below is one you make
yourself and can see in your diff. The steps assume a Laravel 11, 12 or 13
application with the standard `bootstrap/app.php`, on PHP 8.2 or later.

### 1. Require the package

```sh
composer require joetjen/cooper-laravel
```

`joetjen/cooper` and `joetjen/cooper-config` come with it.

Laravel discovers `CooperServiceProvider`, which puts the `cooper:*`
commands on artisan. If your application turns package discovery off,
add `JOetjen\CooperLaravel\CooperServiceProvider::class` to
`bootstrap/providers.php`.

### 2. Replace two bootstrappers in `bootstrap/app.php`

The skeleton's `bootstrap/app.php` returns the application directly.
Keep it in a variable, bind Cooper's two bootstrappers, then return it:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use JOetjen\CooperLaravel\Bootstrap\LoadConfiguration as CooperLoadConfiguration;
use JOetjen\CooperLaravel\Bootstrap\LoadEnvironmentVariables as CooperLoadEnvironmentVariables;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Cooper reads .env and config/config.casc in place of Laravel.
$app->singleton(LoadEnvironmentVariables::class, CooperLoadEnvironmentVariables::class);
$app->singleton(LoadConfiguration::class, CooperLoadConfiguration::class);

return $app;
```

**Why this works for both kernels.** The HTTP kernel
(`public/index.php`) and the console kernel (`artisan`) each keep a
list of bootstrapper class names. Both start with
`LoadEnvironmentVariables` and `LoadConfiguration`, and both run the
list through `$app->bootstrapWith()`, which resolves every class
**through the container**. Binding the two class names replaces them
for every kernel, before any kernel runs. Laravel's events and hooks
for those bootstrappers (`afterLoadingEnvironment()`,
`bootstrapped: ...`) still fire under the original names.
`config:cache` builds a fresh application from `bootstrap/app.php`, so
the cache is filled by the same bootstrappers.

Two other routes were considered and rejected:

- *Subclassing both kernels* to override `$bootstrappers` means two
  classes, two more bindings, and copying a list Laravel may change.
- *`afterBootstrapping()` hooks* run only after Laravel's own loader
  has already read `config/*.php`.

Cooper's classes extend Laravel's own. They change only how `.env` and
the configuration files are read, and everything else behaves as
Laravel's does.

### 3. Write `config/config.casc`

To start from scratch:

```sh
php artisan cooper:init
```

This writes `config/config.casc` and an empty `config/{dev,test,prod}/app.casc`
(see [the document](#the-document)). If the application already has
`config/*.php`, migrate them instead, as described next.

## Migrating `config/*.php`

Once step 2 is in place, `config/*.php` is no longer read. Until the
CASC says what those files said, the application runs on the
framework's defaults. Migrate in this order:

### 1. Import

```sh
php artisan cooper:import --app --all
```

- `--app` translates each of the application's `config/<name>.php`
  into `config/app/<name>.casc`.
- `--all` translates each package's configuration into
  `config/packages/<name>.casc`. These are the files packages publish
  or merge (see [Commands](#commands)).
- `config/config.casc` imports `packages/*.casc`, then `app/*.casc`,
  then the environment's overlay, each once. If the document does not
  exist yet, it is created together with `config/dev/`, `config/test/`
  and `config/prod/`.

The PHP is **parsed, never run**. Each value is written exactly where
CASC can express it (see the [translation table](#what-cooperimport-translates)),
and as `!php("""...""")` where it cannot. The command ends with a
summary:

```text
355 keys exact, 17 via !php, 0 skipped
via !php:
  app.providers
  database.connections.mysql.options
  ...
```

With `--required`, the summary also lists every variable the import
now requires (see below).

### 2. Read the summary

- **`via !php`**: code that is run when the configuration loads. Many
  such values could be written more simply. For example,
  `ServiceProvider::defaultProviders()->...` usually does not belong in
  `app.providers` at all.
- **`skipped`**: closures, and code that reads a variable the PHP file
  defined. These have no CASC form. Move them into a service provider.
- **`env('X')` with no default** was `null` while `X` was unset, so it
  is written as `${X:nil}`, which reads an unset `X` the same way.
  Inside a concatenation it is `${X:''}`, and under a cast it is the
  cast's zero value (`!int(${X:0})`). To make such variables required
  instead, import with `--required`: it writes the strict `${X}` and
  lists each one under **`required now`**. `${X}` fails the load while
  `X` is unset, and every artisan command loads the configuration, so
  **artisan does not start** until each listed variable is set.

### 3. Delete the PHP

Once the CASC says what the PHP said:

```sh
rm config/*.php
php artisan config:clear
```

A nested directory of PHP files (`config/services/mail.php`) goes too,
by hand. Do not remove the directories that now hold CASC:
`config/app/`, `config/packages/` and `config/<env>/`.

Laravel no longer reads these files, and leaving them in place only
misleads the next reader. Package files you published earlier
(`config/sanctum.php`, ...) go too. They were imported with `--app`.

### 4. Check it

```sh
php artisan cooper:check        # the document loads, with this integration's tags
php artisan config:show database
php artisan about
```

## The document

`config/config.casc` sits in the application's config path. Each
top-level block becomes one configuration "file":

| CASC | Laravel |
|---|---|
| `app { name = "Shop" }` | `config('app.name')` |
| `database.connections.mysql.host = "db"` | `config('database.connections.mysql.host')` |
| `reports { per_page = 25 }` | `config('reports.per_page')`, a file Laravel has never heard of |

**Underneath the document**, exactly as under the PHP files it replaces:

- **The framework's defaults** (`vendor/laravel/framework/config/*.php`)
  are merged by Laravel's own rule. A block replaces each default
  top-level key it sets. `database.connections`, `cache.stores`,
  `logging.channels`, `filesystems.disks`, `queue.connections`,
  `mail.mailers`, `broadcasting.connections` and `auth.guards`/`providers`/`passwords`
  are merged one level deeper, so an added connection keeps the
  default ones.
- **Each package's defaults**, which its provider merges with
  `mergeConfigFrom()` after the document has loaded, sit under whatever
  the document set for that package (one level deep, as Laravel merges).

**Values arrive as plain PHP values**, converted by php-cooper-config:

| CASC | `config()` returns |
|---|---|
| `2MiB` | `2097152`, in bytes |
| `1500ms`, `2s` | `1500`, `2000`, in **milliseconds**. An option Laravel reads in seconds or minutes needs the number (`lifetime = 120`) |
| `10.0.0.1`, `10.0.0.0/8` | the text |
| `(52.52, 13.405)` | a list |
| `debug` (an atom) | `"debug"` |
| `2026-10-06` and other dates and times | their CASC text |
| `*password = ...` (a secret) | the value, revealed |

A block may handle its own secrets differently with php-cooper-config's
`cooper-secrets` key: `cooper-secrets = keep` leaves each one a
`CooperSecret`, and `cooper-secrets = "App.Secret"` wraps each one in
your own class. Neither survives `config:cache`. See
[Limits](#limits).

**Per-environment settings** are plain CASC: `import "${COOPER_ENV}/*.casc"`.
`COOPER_ENV` falls back to `APP_ENV`, and Laravel's names map onto
Cooper's: `local` and `development` become `dev`, `testing` becomes
`test`, `production` becomes `prod`. Anything else (`staging`) is used
as is. `php artisan --env=production ...` counts as
`APP_ENV=production`. A glob import that matches no file is an error
(CASC.md §5.1), so every environment you run needs at least one file in
its directory. `cooper:init` and `cooper:import` create an empty
`app.casc` in each.

`app.env` and `app.timezone` are read from the loaded configuration,
as they are from `config/app.php`. With no document at all, the
application runs on the framework's and the packages' defaults, which
is why `cooper:init` can run in a new application.

## `.env` files

`LoadEnvironmentVariables` calls Cooper's `Dotenv::export()`, which
writes what the files define into the process environment (`getenv()`,
`$_ENV`, `$_SERVER`). That is where `env()`, Laravel and packages look.
The document reads the same files through `${NAME}`, using the same
rules:

1. `.env`
2. `.env.<COOPER_ENV>`, for example `.env.dev`, `.env.test` or `.env.prod`
3. `.env.local`
4. the real environment, which always wins: a variable that is already
   set is never overwritten

Differences from Laravel's loader:

- **`.env.testing` becomes `.env.test`**, and `.env.production` becomes
  `.env.prod`. These are Cooper's environment names, matching
  `config/test/` and `config/prod/`.
- **`.env.local` is read in every environment**, last among the files.
- `$app->loadEnvironmentFrom()` and `.env.encrypted`
  (`env:encrypt`/`env:decrypt`) are not used.
- As in Laravel, nothing is read once the configuration is cached.
  `env()` outside the configuration then returns `null`.

## Tags

Every load has these tags, named exactly like the PHP they stand for:

| Tag | Gives |
|---|---|
| `!base_path("x")` | `base_path('x')`, evaluated on the machine that loads the document; `!base_path("")` for the directory itself |
| `!app_path`, `!config_path`, `!database_path`, `!lang_path`, `!public_path`, `!resource_path`, `!storage_path` | the same, for each helper |
| `!php_const("PDO::MYSQL_ATTR_SSL_CA")` | a class constant's, an enum case's or a global constant's value. An undefined constant fails the load |
| `!php("""PHP_INT_SIZE * 8""")` | the value of one PHP expression, evaluated at load with `$app` in scope |

```casc
logging.channels.single.path = !storage_path("logs/laravel.log")
database.connections.sqlite.database = ${DB_DATABASE:!database_path("database.sqlite")}
database.connections.mysql.options = !php("""[PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')]""")
```

**`!php` takes only a string literal written in the document**:
`"..."`, `'...'` or `"""..."""`, with nothing interpolated. Anything
else in its place refuses the load before any code runs, and the error
names the key and the file. That includes `${...}`, `@{...}`,
`%{...}`, a resolver, another tag, an interpolating string, a loop
variable, or a variable that holds a literal. No value from the
environment, a `.env` file or another file can ever become code. The
rule is php-cooper's `LiteralTag`.

Further notes on `!php`:

- **Write it triple-quoted.** In a double-quoted string, a `!name(` such
  as `!empty($x)` reads as a tag, which makes the string interpolate,
  and that is refused. `"""..."""` never interpolates.
- **It is one expression.** `return` is implied. For statements, use a
  closure that is called at once:
  `!php("""(function () { $x = 1; return $x + 1; })()""")`.
- **It runs during `LoadConfiguration`.** Only the container, `$app`,
  `env()` and the path helpers are ready then. Facades and
  `config()` are not.
- **An exception fails the load.** That includes a parse error, and the
  message names the key and the file:
  `!php(...) failed at app.name: no name today (/app/config/config.casc, line 4, column 10)`.

The path helpers and `!php_const` accept any string, from anywhere:
`!storage_path(${LOG_DIR})` is fine, because a path or a constant name
is data, not code. An application's own tag of the same name takes
precedence (see [Customising](#customising)).

## Commands

| Command | What it does |
|---|---|
| `cooper:init` | Scaffolds `config/config.casc` and `config/{dev,test,prod}/app.casc`. Refuses to overwrite anything (php-cooper-config's command) |
| `cooper:check [--path=FILE]` | Loads the document as the application does, with these tags and `LoadConfiguration`'s options, and reports its top-level keys. Exits 1 with the error if it does not load (php-cooper-config's command) |
| `cooper:import <name>` | One package's configuration (`sanctum` for `config('sanctum.*')`) into `config/packages/<name>.casc`. An unknown name lists the known ones |
| `cooper:import --all` | Every package's configuration found |
| `cooper:import --app` | The application's own `config/*.php` into `config/app/<name>.casc` (`config/services/mail.php` is `services.mail`) |
| `cooper:import ... --required` | `env('X')` without a default becomes `${X}`, which fails the load while `X` is unset, instead of `${X:nil}`. Each such variable is listed as *required now* |
| `cooper:import ... --force` | Overwrites a CASC file that would change. Without it, the file is left alone, the difference is printed, and the command exits 1 |

php-cooper-config's `cooper:cache:clear` is deliberately not on artisan:
it clears a compiled cache this integration never reads. Laravel's own
`config:cache` is the cache here, and `config:clear` clears it.

A package's configuration is found two ways:

- through its provider's `publishes([... => config_path('x.php')], 'config')`;
- through `mergeConfigFrom(__DIR__.'/../config/x.php', 'x')` (or
  `replaceConfigRecursivelyFrom()`). These calls are found by parsing
  the provider's source, never by running it. Every registered provider
  is searched, deferred ones included.

### What `cooper:import` translates

| PHP | CASC |
|---|---|
| strings, numbers, `true`/`false`/`null`, arrays | the same, exactly; an associative array is a block, and a key's comment comes along |
| `env('X')` | `${X:nil}`, `null` while `X` is unset as `env()` gave; with `--required`, `${X}`, which **fails the load while `X` is unset** (listed as *required now*) |
| `env('X', true)`, `env('X', 3)`, `env('X', 0.5)` | `!bool(${X:true})`, `!int(${X:3})`, `!float(${X:0.5})` |
| `env('X', null)`, `env('X', 'd')` | `${X:nil}`, `${X:"d"}` |
| `env('X', storage_path('x'))`, `env('X', Foo::BAR)`, `env('X', Foo::class)` | `${X:!storage_path("x")}`, `${X:!php_const("Foo::BAR")}`, `${X:"Foo"}` |
| `(bool) env('X', false)`, `(int)`, `(float)`, `(string)` | typed as the default says, when the cast agrees with it |
| `(bool) env('X')`, `(int)`, `(float)`, `(string)` | `!bool(${X:false})`, `!int(${X:0})`, `!float(${X:0.0})`, `${X:""}`, the cast's value of `null`; `!bool(${X})`, ... with `--required` |
| `'redis://' . env('HOST') . ':6379'` | `"redis://${HOST:''}:6379"`, `null` concatenated being `''` (`"redis://${HOST}:6379"` with `--required`; a default is single-quoted inside: `${HOST:'localhost'}`) |
| `explode(',', env('X', 'a,b'))` | `${X[]:["a", "b"]}` |
| `explode(',', env('X'))` | `${X[]:[]}`; `${X[]}` with `--required` |
| `App\Models\User::class` | `!module("App.Models.User")`; `"Acme\\lower_case"` when a segment is not PascalCase |
| `storage_path('x')`, `base_path()` and the other path helpers | `!storage_path("x")`, `!base_path("")` |
| `PDO::MYSQL_ATTR_SSL_CA`, `PHP_EOL` | `!php_const("PDO::MYSQL_ATTR_SSL_CA")`, `!php_const("PHP_EOL")` |
| anything else | `!php("""<the code>""")`, with class names spelled out in full (`\Illuminate\Support\Str::slug(...)`), since the file's `use` lines do not come along |
| an array with a key that is not a literal, or a spread (`...$x`) | `!php` as a whole |
| a closure, code reading a variable the file defined | left out, and listed as *skipped* |

The PHP files are never changed.

## `config:cache`

```sh
php artisan config:cache
```

This works as it always has. Laravel builds a fresh application, which
loads the document through Cooper, and writes the result to
`bootstrap/cache/config.php`. While that file exists, Laravel uses it
and **CASC is not read at all**: neither the document nor the `.env`
files. `config:clear` returns to CASC.

What is cached is what the document produced on the machine that ran
the command. That includes `${...}` values, `!php` results and the
absolute paths the path helpers produced. This is the same behaviour as
`env()` and `storage_path()` in `config/*.php`. Run `config:cache`
where the application runs, as part of the deploy.

## Customising

Give the bootstrappers options by binding them with a closure in
`bootstrap/app.php`:

```php
$app->singleton(LoadConfiguration::class, fn () => new CooperLoadConfiguration(
    options: [
        'resolvers' => ['vault' => fn (string $path) => Vault::read($path)],  // !{vault:db/password}
        'tags'      => ['upper' => fn (mixed $value) => strtoupper((string) $value)],
        'modules'   => ['Store' => App\Cache\RedisStore::class],
    ],
    document: dirname(__DIR__) . '/config/app.casc',   // instead of config/config.casc
));
```

- `options` are Cooper's load options (`resolvers`, `tags`, `modules`,
  `importSchemes`, `env`, and the `.env` options). An application tag
  with the same name as one of this integration's takes precedence.
- `document` defaults to `config/config.casc` under the config path.
- `cooper:check` loads with the same options and document.
- These closures run before the configuration exists, so they cannot
  use `config()` or facades.

`LoadEnvironmentVariables` takes Cooper's `.env` options the same way
(`new CooperLoadEnvironmentVariables(['dotenvFiles' => [...]])`). Give
`LoadConfiguration` the same ones, so that the document and `env()`
read the same files.

## Limits

- **`env()` and CASC read values differently.** Laravel's `env()` turns
  `"true"`, `"(false)"`, `"null"` and `"empty"` into PHP values.
  `${X}` is always a string; `!bool(...)` makes it a boolean from
  `true`/`1`/`yes`/`on` or `false`/`0`/`no`/`off`, lower case only, so
  `APP_DEBUG=1` reads as true but `(true)` fails the load.
- **Lists from `${X[]}` split on `,` and `;` and trim each item.**
  `explode(',', ...)` did neither.
- **Durations are milliseconds** (php-cooper-config's rule). For an
  option in seconds or minutes, write the number.
- **Merging is Laravel's, not CASC's, below the document.** A package's
  `mergeConfigFrom()` merges one level deep. Setting
  `probe { connections.main.host = "x" }` replaces the package's whole
  `connections` value, exactly as a published `config/probe.php` would.
  Within the document, CASC merges deeply as usual.
- **`config:cache` refuses what `var_export()` cannot write.** That
  covers `cooper-secrets = keep`, a `cooper-secrets` class that cannot
  be exported, and a `!php` value that is a closure or an object
  without `__set_state()`. Laravel reports them as "not serializable".
- **`!php` code is not sandboxed.** It is written in the document and
  runs with the application's rights, like the PHP it replaces. Review
  it as you would review code.
- **`cooper:import` translates only what it can read.** A file that
  returns anything but an array literal, or whose top-level keys are
  not literals, is refused. Configuration a provider builds at runtime
  (`config([...])` in `boot()`) is not found.

## Development

```sh
composer install
composer test       # PHPUnit
composer analyse    # PHPStan, level 8
composer precommit  # both
```

The integration tests copy Testbench's Laravel skeleton into a scratch
directory, write `bootstrap/app.php` exactly as [step 2](#2-replace-two-bootstrappers-in-bootstrapappphp)
says, and boot it through the real HTTP and console kernels, each test
in a process of its own. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[Apache License 2.0](LICENSE). Copyright 2026 Jan Oetjen.
