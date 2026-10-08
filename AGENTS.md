# AGENTS.md

Instructions for AI agents working in this PHP codebase.

## Communication

- Every response starts with the user's first name. Determine it from `git config user.name` (take the first name); if that's unavailable or ambiguous, ask once. Remember the answer for the rest of the session rather than re-deriving or re-asking.

## Before every commit

- Run `composer run precommit` and make sure it passes. No exceptions. It expands to `vendor/bin/phpstan analyse --level=8` (the paths are in `phpstan.neon`) followed by `phpunit`.

## Tests

- Tests are written first: a failing test, then the change that makes it pass. Test names read as sentences (`testTheApplicationsOwnConfigPhpFilesAreIgnored`).
- Tests must stay current with behavior: a change to what code does needs its tests updated in the same commit, not "later".
- Put new tests in the file that matches what you're touching, mirroring `src/`'s own layout under `tests/`. Add a new test file only when a change doesn't fit any existing one.
- Integration tests boot a scratch project (`tests/Support/Project.php`): Testbench's Laravel skeleton with `bootstrap/app.php` written exactly as `README.md` says, through the real HTTP and console kernels. Each integration test class carries `#[RunTestsInSeparateProcesses]` (PHPUnit does not inherit it): booting Laravel and exporting `.env` change process-wide state.

## Documentation

- Treat every documentation surface touched by a change as part of that change: PHPDoc blocks, `README.md`, `CHEATSHEET.md`, `CHANGELOG.md`, and comments explaining non-obvious behavior. Comments explain *why*.
- Every public class needs a class-level PHPDoc block. Every public method needs a `@param`/`@return`/`@throws`-annotated PHPDoc block.
- Before documenting any example or claim about behavior, run it.
- Update `CHANGELOG.md` for every user-facing change, following [Keep a Changelog](https://keepachangelog.com/): entries under `[Unreleased]` as you work, moved under a version heading on release.

## Scope

- CASC replaces Laravel's configuration: `config/config.casc` fills the repository and `config/*.php` is not read. The framework's and packages' defaults stay underneath, merged by Laravel's own rules; `config:cache` keeps working and, while it is cached, CASC is not read.
- Installation is manual and documented step by step in `README.md`: two container bindings in `bootstrap/app.php`. No install command, no code that edits an application's files except the `cooper:*` commands it runs on purpose.
- `!php` takes only a literal written in the document (php-cooper's `LiteralTag`). Never let a value from the environment, a resolver or another file reach code.
- `cooper:import` parses PHP, never runs it. `README.md`'s translation table is the contract.
- CASC itself is `joetjen/cooper`'s, conversion and the shared commands `joetjen/cooper-config`'s. A need that looks like either belongs there, with its own tests and docs.

## Static analysis

- `vendor/bin/phpstan analyse --level=8` is part of `composer run precommit` -- keep it clean, with a specific justification for anything that must be ignored, never a blanket one.

## Coding style

- Follow `.editorconfig` (4-space indent, PSR-12). `declare(strict_types=1)` in every PHP file.
- Target PHP 8.2+ (`composer.json`'s `require.php`) and Laravel 11 and 12.
- Classes that are not part of the public API are marked `@internal`.

## Git workflow

- Use [git flow](https://nvie.com/posts/a-successful-git-branching-model/): `main` (releases), `develop` (integration), `feature/*`, `release/*`, `hotfix/*`, `support/*`.
- No direct commits to `main` or `develop`. Branch, then merge/PR back. Base pull requests on `develop` unless you're specifically preparing a release.

## Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/): `<type>[optional scope]: <description>`.
- Breaking changes: `!` after type/scope, or a `BREAKING CHANGE:` footer.

## Versioning

- Use [Semantic Versioning](https://semver.org/). Tags match the changelog entry when tagging a release.

## License

- Apache-2.0, never MIT.
