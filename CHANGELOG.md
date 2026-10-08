# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-08

### Added

- Laravel 11, 12 and 13 (Laravel 13 on PHP 8.3 or later), with
  `orchestra/testbench` 9, 10 and 11 to test against them.
- `Bootstrap\LoadConfiguration`, bound in `bootstrap/app.php` in place
  of Laravel's: `config/config.casc` fills the configuration
  repository, and `config/*.php` is not read.
  - Each top-level block is one configuration "file".
  - The framework's defaults sit underneath, merged by Laravel's own
    rule, and the packages' `mergeConfigFrom()` defaults arrive under
    the document as before.
  - Values are converted by php-cooper-config: byte sizes in bytes,
    durations in milliseconds, addresses as text, secrets revealed
    unless a block's `cooper-secrets` says otherwise. Atoms, dates and
    big integers become plain strings, so `config:cache` can write them.
  - `COOPER_ENV` comes from `APP_ENV` (`local`/`testing`/`production`
    are `dev`/`test`/`prod`), and `artisan --env=X` counts as `APP_ENV=X`.
  - A missing document leaves the defaults; a document that does not
    load stops the boot with a `ConfigError` naming it.
  - Cooper's load options and the document's path are constructor
    arguments.
- `Bootstrap\LoadEnvironmentVariables`, bound in place of Laravel's:
  Cooper's `Dotenv::export()` writes `.env`, `.env.<COOPER_ENV>` and
  `.env.local` into the process environment for `env()`, `$_ENV`,
  `$_SERVER` and `getenv()`. It never overwrites the real environment,
  and reads nothing while the configuration is cached.
- `config:cache` keeps working. It caches what the document produced,
  and a cached configuration is used without reading CASC.
- Tags on every load: `!base_path`, `!app_path`, `!config_path`,
  `!database_path`, `!lang_path`, `!public_path`, `!resource_path`,
  `!storage_path`, `!php_const("Class::NAME")`, and `!php("""...""")`.
  `!php` evaluates one PHP expression at load with `$app` in scope. It
  takes only a string literal written in the document (php-cooper's
  `LiteralTag`), and its failures name the key and the file.
- `CooperServiceProvider`, discovered by Laravel, puts on artisan:
  - php-cooper-config's `cooper:init` and `cooper:check`, rooted at the
    base path. `cooper:check` loads with the application's tags, options
    and document. Its `cooper:cache:clear` is not registered: it clears a
    compiled cache this integration never reads -- `config:cache` is the
    cache here, and `config:clear` clears it.
  - `cooper:import <name> | --all | --app [--required] [--force]`, which
    translates PHP configuration into CASC by parsing it, never running
    it:
    - a package's (found through `publishes(..., 'config')`,
      `mergeConfigFrom()` and `replaceConfigRecursivelyFrom()`) into
      `config/packages/<name>.casc`, and the application's own
      `config/*.php` into `config/app/<name>.casc`;
    - literals exactly, `env()` as `${...}` typed by its default,
      concatenations as interpolated strings, `explode(',', env(...))`
      as `${X[]:[...]}`, `::class` as `!module`, path helpers and
      constants as tags, anything else as `!php`, closures skipped;
    - comments carried over;
    - `config/config.casc` wired to `import "packages/*.casc"` and
      `"app/*.casc"`, once each, before the environment's overlay;
    - existing files diffed and left alone without `--force`;
    - `env('X')` without a default as `${X:nil}` -- `${X:''}` inside a
      concatenation, the cast's zero value under a cast -- reading an
      unset `X` as `env()` did. `--required` writes the strict `${X}`
      instead, which fails the load while `X` is unset;
    - a closing summary of exact, `!php` and skipped keys, and, under
      `--required`, of the variables `${X}` now requires.
