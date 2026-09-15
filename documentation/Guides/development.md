# Development guide

Use the [setup guide](../../docs/SETUP.md) to configure and start the Docker environment. [Production deployment](../../docs/production.md), [updating](../../docs/updating.md), and [troubleshooting](../../docs/troubleshooting.md) remain in the operations documentation.

## Find the relevant code

| Location | Contents |
|---|---|
| `src/Controller/` | HTTP flows organized by product area |
| `src/Service/`, `src/Repository/` | Business logic, integrations, and data access |
| `src/Entity/`, `migrations/` | Doctrine entities and schema migrations |
| `src/Message/`, `src/MessageHandler/` | Messenger work and handlers |
| `src/ReadModel/`, `src/Service/Cache/` | Redis projections and caching |
| `src/Service/Graph/` | Publication reference and revision projections |
| `src/Twig/Components/`, `templates/components/` | PHP components and matching Twig templates |
| `assets/` | JavaScript, TypeScript, CSS, and images |
| `packages/unfold-bundle/` | Locally developed Unfold Composer package |
| `config/bundles.php`, `composer.json`, `composer.lock` | Registered bundles and packaged dependencies |
| `documentation/` | User guides, feature behavior, and protocol references |
| `skills/` | Task-specific implementation guides |

Read the [architecture guide](architecture.md) for application/package boundaries. Check Composer to locate extracted bundle code.

## Working conventions

Read [AGENTS.md](../../AGENTS.md), any applicable controller-level `AGENT.md`, and the relevant guide in [skills/README.md](../../skills/README.md) before making changes.

- Run application commands inside Docker.
- Put JavaScript and CSS in `assets/`; use Stimulus controllers for browser behavior. Add JS dependencies through the import map.
- Follow the existing Atom, Molecule, and Organism component layout when adding Twig components.
- Use translation keys for user-facing text and update the locale files.
- Use the existing flat visual style: no shading or rounded edges.
- Keep one maintained document per feature in its area under `documentation/`. Update the [documentation index](../INDEX.md) and the top development version of [CHANGELOG.md](../../CHANGELOG.md).

## Common commands

Run these from the repository directory with the PHP service running:

```bash
# Discover console commands and routes
docker compose exec php bin/console list
docker compose exec php bin/console debug:router

# Compile assets after JS/CSS changes
docker compose exec php bin/console asset-map:compile
docker compose exec php bin/console importmap:require <package-name>

# Generate a migration, review it, then apply it
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console doctrine:migrations:migrate

# Inspect application and worker logs
docker compose logs -f php worker worker-relay worker-profiles
```

For recurring implementation tasks, use the existing guides: [entities](../../skills/add-doctrine-entity.md), [async handlers](../../skills/create-async-message-handler.md), [Stimulus](../../skills/create-stimulus-controller.md), [Live Components](../../skills/create-twig-live-component.md), [translations](../../skills/add-translations.md), and [documentation](../../skills/add-feature-documentation.md).

## Validation

Run checks appropriate to the change. PHPUnit configuration is in [phpunit.xml.dist](../../phpunit.xml.dist); the Unfold suite targets `packages/unfold-bundle/tests`. Protocol `.feature` files in `tests/NIPs/` are not PHPUnit tests.

```bash
docker compose exec php bin/phpunit --list-suites
docker compose exec php bin/phpunit --testsuite Unit
docker compose exec php bin/phpunit
```

See [tests/README.md](../../tests/README.md) for test setup and [Rector guidance](../../skills/run-rector.md) before automated PHP modernization. Start Rector with a dry run.

Documentation-only changes should verify relative links, referenced paths, commands, and claims against the current source. Keep detailed relay, worker, and cron procedures in their feature/operations guides rather than copying them into onboarding.

## Contributing

Keep changes focused, follow existing conventions, and describe the final behavior and validation in the pull request. Feature documentation should describe the implementation that exists; remove superseded plans and completion summaries once their useful information is incorporated into the maintained guide.

The Symfony profiler is available in development. See [Xdebug setup](../../docs/xdebug.md) for step debugging and the [operations index](../../docs/INDEX.md) for deployment-specific commands.
