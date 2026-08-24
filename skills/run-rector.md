# Skill: Run Rector for Automated Code Quality

Use this skill to safely apply automated refactoring and PHP modernisation via Rector. Rector is installed as a dev dependency (`rector/rector`) and configured in `rector.php`.

---

## Current configuration (`rector.php`)

```php
return RectorConfig::configure()
    ->withPaths([__DIR__.'/assets', __DIR__.'/config', __DIR__.'/public', __DIR__.'/src', __DIR__.'/tests'])
    ->withTypeCoverageLevel(0)    // type-hint coverage rules  (0 = off)
    ->withDeadCodeLevel(0)        // dead code removal rules   (0 = off)
    ->withCodeQualityLevel(0);    // code quality rules        (0 = off)
```

Levels range **0 (off) → higher numbers (more rules)**. Increase them gradually — never jump from 0 to max in one pass.

---

## Basic commands

All commands run **inside the Docker container**.

### Dry-run (preview only — no files changed)

```bash
docker compose exec php vendor/bin/rector process --dry-run
```

Always run dry-run first. Review the diff before applying.

### Apply changes

```bash
docker compose exec php vendor/bin/rector process
```

### Process a single file or directory

```bash
docker compose exec php vendor/bin/rector process src/Service/GenericEventProjector.php --dry-run
docker compose exec php vendor/bin/rector process src/Entity/ --dry-run
```

### Show which rules are active

```bash
docker compose exec php vendor/bin/rector list-rules
```

---

## Raising quality levels

Edit `rector.php` to increase levels one at a time. Always dry-run after each change.

```php
return RectorConfig::configure()
    ->withPaths([...])
    ->withPhpSets()                 // enable PHP 8.3 modernisation (readonly, match, enums, etc.)
    ->withTypeCoverageLevel(1)      // start with level 1, review, then go to 2, 3...
    ->withDeadCodeLevel(1)
    ->withCodeQualityLevel(1);
```

### `withPhpSets()` — PHP version modernisation

Enables rules matching the project's actual PHP version (8.3). Safe to turn on. Typical improvements:
- `declare(strict_types=1)` headers
- `readonly` properties
- Named arguments
- First-class callables (`strlen(...)`)
- `match` expressions over `switch`
- Nullsafe operator `?->`

### `withTypeCoverageLevel(n)` — type safety

Adds missing param/return types, property types. Start at level 1 and review each batch.

### `withDeadCodeLevel(n)` — dead code removal

Removes unused variables, assignments, and unreachable code. Very safe at level 1.

### `withCodeQualityLevel(n)` — code style/quality

Simplifies conditions, normalises control flow. Review carefully at each level.

---

## Adding Symfony-specific rules

```php
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([...])
    ->withSets([
        SymfonySetList::SYMFONY_74,           // Symfony 7.4 deprecations
        SymfonySetList::SYMFONY_CODE_QUALITY, // Symfony best practices
        SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES, // Convert @Route → #[Route], etc.
    ]);
```

> **Prerequisite:** install the Symfony Rector rules package:
> ```bash
> docker compose exec php composer require --dev rector/rector-symfony
> ```

---

## Skipping specific rules or files

```php
return RectorConfig::configure()
    ->withPaths([...])
    ->withSkip([
        // Skip a specific rule everywhere
        \Rector\CodeQuality\Rector\Class_\SomeRector::class,

        // Skip a rule for specific files
        \Rector\TypeDeclaration\Rector\Property\TypedPropertyRector::class => [
            __DIR__ . '/src/Entity',   // Doctrine entity types are managed by ORM
        ],

        // Skip an entire directory
        __DIR__ . '/src/UnfoldBundle',
    ]);
```

Common exclusions for this codebase:
- `src/Entity/` for typed property rules — Doctrine ORM handles nullability
- `src/Migrations/` — auto-generated, never touch

---

## Recommended maintenance workflow

### 1. Before a release (or monthly)

```bash
# 1. Dry-run with current config to see pending changes
docker compose exec php vendor/bin/rector process --dry-run

# 2. Apply if the diff looks clean
docker compose exec php vendor/bin/rector process

# 3. Run tests to confirm nothing broke
docker compose exec php bin/phpunit

# 4. Run PHPStan to catch any type issues Rector introduced
docker compose exec php vendor/bin/phpstan analyse
```

### 2. After upgrading PHP or Symfony

1. Update `rector.php` to call `->withPhpSets()` (uncomment the line already in the config).
2. Dry-run and review the diff.
3. Apply in small batches by directory: `src/Entity/`, `src/Service/`, etc.
4. Run tests after each batch.

### 3. Gradual level increases

Increase one level at a time per PR:

```
Week 1: withTypeCoverageLevel(1)  → PR, review, merge
Week 2: withTypeCoverageLevel(2)  → PR, review, merge
...
```

---

## PHPStan alongside Rector

PHPStan (level 6, configured in `phpstan.dist.neon`) catches type errors that Rector may introduce. Always run both:

```bash
# Static analysis
docker compose exec php vendor/bin/phpstan analyse

# Automated fixes
docker compose exec php vendor/bin/rector process --dry-run
```

They complement each other: PHPStan identifies the problems, Rector fixes them.

---

## What Rector does NOT fix

- Business logic errors
- Missing test coverage
- Architecture decisions (service responsibilities, layer violations)
- Twig template quality
- JavaScript / TypeScript code (Rector is PHP-only)

---

## Checklist

- [ ] Dry-run first: `vendor/bin/rector process --dry-run`
- [ ] Review the full diff before applying
- [ ] Apply: `vendor/bin/rector process`
- [ ] Run PHPUnit: `bin/phpunit`
- [ ] Run PHPStan: `vendor/bin/phpstan analyse`
- [ ] Commit Rector changes in a dedicated commit (separate from feature work)
- [ ] `CHANGELOG.md` entry added (e.g. `[Improvement] Applied Rector PHP 8.3 modernisation across src/`)

