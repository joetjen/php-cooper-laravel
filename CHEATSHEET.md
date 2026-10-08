# php-cooper-laravel cheatsheet

## Install (manual)

```sh
composer require joetjen/cooper-laravel     # the provider is discovered
```

```php
// bootstrap/app.php: keep the application in $app, then
$app->singleton(\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class, \JOetjen\CooperLaravel\Bootstrap\LoadEnvironmentVariables::class);
$app->singleton(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, \JOetjen\CooperLaravel\Bootstrap\LoadConfiguration::class);
return $app;
```

```sh
php artisan cooper:import --app --all      # config/*.php and packages -> CASC; read the summary
rm config/*.php
php artisan config:clear
php artisan cooper:check
```

## Layout

| File | Holds |
|---|---|
| `config/config.casc` | the document: `import "packages/*.casc"`, `import "app/*.casc"`, `import "${COOPER_ENV}/*.casc"` |
| `config/packages/<name>.casc` | `<name> { ... }`, one package's configuration |
| `config/app/<name>.casc` | what `config/<name>.php` held |
| `config/<env>/*.casc` | that environment's overlay (`dev`, `test`, `prod`) |

## The document

```casc
app { name = "Shop" }                        # config('app.name')
database.connections.mysql.host = "db"       # config('database.connections.mysql.host')
reports { per_page = 25 }                    # any name: config('reports.per_page')
```

Underneath: the framework's defaults (Laravel's merge rule), then each
package's `mergeConfigFrom()`. `2MiB` is bytes, `2s` is **ms**, `info`
is `"info"`, a secret is revealed (`cooper-secrets = keep` to keep it).

## Environments

`APP_ENV` | `COOPER_ENV` | `.env` file | overlay
---|---|---|---
`local`, `development` | `dev` | `.env.dev` | `config/dev/`
`testing` | `test` | `.env.test` | `config/test/`
`production` | `prod` | `.env.prod` | `config/prod/`

`.env` < `.env.<COOPER_ENV>` < `.env.local` < the real environment.
`artisan --env=X` counts as `APP_ENV=X`. A cached configuration reads
no `.env` file and no CASC.

## Tags

```casc
log    = !storage_path("logs/laravel.log")   # also base_path, app_path, config_path,
root   = !base_path("")                      # database_path, lang_path, public_path, resource_path
ca     = !php_const("PDO::MYSQL_ATTR_SSL_CA")
names  = !php("""array_map('strtoupper', ['a', 'b'])""")   # one expression; $app in scope
```

`!php` takes only a literal written in the document: `!php(${X})`,
`!php(@{v})`, `!php("a ${X}")` and `!php(!trim("x"))` refuse the load,
naming the key and the file.

## cooper:import

```sh
php artisan cooper:import sanctum           # one package -> config/packages/sanctum.casc
php artisan cooper:import --all             # every package found (publishes / mergeConfigFrom)
php artisan cooper:import --app             # config/*.php -> config/app/*.casc
php artisan cooper:import --app --required  # env('X') -> ${X} (strict), not ${X:nil}; lists them
php artisan cooper:import --all --force     # overwrite files that would change (else: diff, exit 1)
```

PHP | CASC
---|---
`env('X')` | `${X:nil}` / `${X}` (required now) with `--required`
`env('X', true)` / `3` / `0.5` / `null` / `'d'` | `!bool(${X:true})` / `!int(${X:3})` / `!float(${X:0.5})` / `${X:nil}` / `${X:"d"}`
`(int) env('X')` / `(bool)` / `(float)` / `(string)` | `!int(${X:0})` / `!bool(${X:false})` / `!float(${X:0.0})` / `${X:""}`
`'a' . env('X') . 'b'` | `"a${X:''}b"` / `"a${X}b"` with `--required`
`explode(',', env('X', 'a,b'))` | `${X[]:["a", "b"]}`
`Foo\Bar::class` | `!module("Foo.Bar")`
`storage_path('x')` | `!storage_path("x")`
`PDO::ATTR_X`, `PHP_EOL` | `!php_const("PDO::ATTR_X")`
anything else | `!php("""<code>""")`
closures | skipped, listed

## Commands

```sh
php artisan cooper:init          # scaffold config/config.casc and config/{dev,test,prod}/
php artisan cooper:check         # does it load? (same tags and options as the application)
php artisan config:cache         # works as always: caches what CASC produced
```

## Options

```php
$app->singleton(LoadConfiguration::class, fn () => new CooperLoadConfiguration(
    options: ['resolvers' => [...], 'tags' => [...], 'modules' => [...]],
    document: dirname(__DIR__) . '/config/config.casc',
));
```
