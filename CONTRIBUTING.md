# Contributing to php-cooper-laravel

Thanks for considering a contribution. This document covers what you
need to know before opening an issue or a pull request.

## Getting started

```sh
git clone <this repository>
git clone <php-cooper's repository> ../php-cooper                 # until joetjen/cooper is published
git clone <php-cooper-config's repository> ../php-cooper-config   # until joetjen/cooper-config is published
cd php-cooper-laravel
composer install
composer test
```

This should finish with no failures on a clean checkout. If it does
not, please open an issue before doing anything else, because that is
a bug in its own right.

## Project layout

- `src/Bootstrap/`: `LoadConfiguration` and `LoadEnvironmentVariables`,
  Laravel's two bootstrappers replaced; `NamedEnvironment`
  (`artisan --env`).
- `src/Tags.php`: the path helpers, `!php_const` and `!php`.
- `src/ConfigValues.php`: loaded values as configuration values.
- `src/CooperServiceProvider.php`: the `cooper:*` commands on artisan.
- `src/Command/ImportCommand.php`, `src/Import/`: `cooper:import`,
  made up of the PHP-to-CASC translator, the sources it finds, and the
  line diff.
- `tests/`: PHPUnit, mirroring `src/`. `tests/Support/` holds the
  scratch project (Testbench's skeleton, installed as `README.md`
  says) and the probe package.

## Making a change

1. **Tests first.** Write a failing test whose name says, as a
   sentence, what should happen. Then make the change.
2. **Boot it.** Behaviour a Laravel application sees is tested through
   the scratch project's real kernels, not by calling a bootstrapper
   by hand.
3. **Keep the translation honest.** A change to what `cooper:import`
   writes updates `README.md`'s translation table.
4. **Run the full verification pass before opening a PR:**

   ```sh
   composer run precommit
   ```

5. **Keep documentation current.** Update PHPDoc, `README.md`,
   `CHEATSHEET.md` and `CHANGELOG.md` (`[Unreleased]`) in the same
   commit. Run any example before you document it.

## Reporting bugs

Please include:

- the CASC document, or a minimal excerpt;
- the PHP configuration file, for `cooper:import`;
- your Laravel version;
- what you expected and what happened, with the full error message.

## License

By contributing, you agree that your contributions will be licensed
under the project's [Apache License 2.0](LICENSE).
