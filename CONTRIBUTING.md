# Contributing

Thanks for your interest in improving **Stateful Chunking Upload for Laravel**!
Contributions of all kinds are welcome: bug reports, fixes, tests, documentation
and features. This guide explains how to get set up and what the project expects
from a change.

## Reporting issues

- Search existing issues first to avoid duplicates.
- For bugs, include the package version, Laravel and PHP versions, the cache
  store and storage disk in use, and a minimal way to reproduce the problem.
- For security issues, **do not** open a public issue — see [SECURITY.md](SECURITY.md).

## Requirements

- PHP **8.2+**
- [Composer](https://getcomposer.org/)

## Getting started

```bash
git clone https://github.com/JuanGuerreroDev/stateful-chunking-upload.git
cd stateful-chunking-upload
composer install
```

`composer.lock` is committed so that local work and CI run against the same
dependency set. Use `composer install` (not `composer update`) unless you are
deliberately bumping a dependency.

## Development workflow

Run the full quality suite before opening a pull request. Composer scripts wrap
each tool:

```bash
composer test           # Pest test suite
composer test:coverage  # tests with coverage, fails under 90%
composer lint:test      # check code style with Pint (no changes)
composer lint           # fix code style with Pint
composer analyse        # PHPStan static analysis (level 10)
```

Taint analysis (security data-flow scan) runs in CI via Psalm and can be run
locally too:

```bash
vendor/bin/psalm --taint-analysis
```

All of these run in CI on every pull request against `main` and must pass before
a change can be merged.

## Coding standards

- **Style:** [Laravel Pint](https://laravel.com/docs/pint). Keep `composer lint:test` clean.
- **Static analysis:** PHPStan at **level 10** and Psalm taint analysis must both pass.
- **Tests:** new behaviour needs tests; coverage must stay at or above **90%**.
- **Architecture:** the package follows **Hexagonal Architecture** and **SOLID**
  principles. Keep the domain layer free of framework and infrastructure concerns
  (no Eloquent, `Storage`, `Cache` or `Request` inside domain logic); wire
  dependencies through interfaces at the edges.

## Commit messages

This project uses [Conventional Commits](https://www.conventionalcommits.org/).
Prefix the subject with a type and optional scope, for example:

```
feat(routing): add StatefulChunking::routes() macro
fix(session): release the lock when reassembly fails
test(upload): cover out-of-order chunk delivery
ci(deps): pin analysis dependencies via the lock file
```

Keep commits focused and write them in the imperative mood.

## Pull requests

1. Fork the repository and create a topic branch from `main`
   (e.g. `feat/short-description` or `fix/short-description`).
2. Make your change, adding tests and updating documentation as needed.
3. Ensure the full suite above is green locally.
4. Open a pull request against `main` with a clear description of the change and
   the motivation behind it. Link any related issues.
5. Keep the pull request focused; unrelated changes are best split into separate
   pull requests.

CI must be green for a pull request to be merged. Thanks again for contributing!
