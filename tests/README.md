# Test Suite Guide

This directory contains PHPUnit tests and protocol feature specs.

## PHPUnit Suites

Use `phpunit.xml.dist` suite names with `--testsuite`:

- `Project Test Suite` - all PHPUnit tests under `tests/`
- `Unit` - pure unit tests in `tests/Unit/`
- `Service` - integration-style tests in `tests/Service/`
- `Security` - security/authentication tests in `tests/Security/`
- `UnfoldBundle` - hosted magazine tests in `tests/UnfoldBundle/`
- `Util` - utility-focused tests in `tests/Util/`

## Protocol Specs

- `tests/NIPs/` contains Gherkin `.feature` specs.
- These are not part of PHPUnit suites.
- Run them with Behat when configured.

## Common Commands

```bash
docker compose exec php bin/phpunit --list-suites
docker compose exec php bin/phpunit --testsuite Unit
docker compose exec php bin/phpunit --testsuite "Project Test Suite"
```

