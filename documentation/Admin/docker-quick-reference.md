# Docker Development Reference

The application and default strfry relay are defined in `compose.yaml`; `compose.override.yaml` supplies local development overrides. Production uses `compose.prod.yaml` explicitly. See [Production Deployment Validation](deployment-validation.md) for production build, deployment, migration, and health checks.

## Local services

```bash
docker compose up -d
docker compose ps
docker compose logs --tail=100 php worker worker-relay worker-profiles cron
docker compose exec php sh
```

Application commands run in the `php` service. There is no `frankenphp` service name in these commands.

## Development tasks

```bash
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php bin/console asset-map:compile
docker compose exec php bin/console importmap:require package-name
docker compose exec php bin/console cache:clear
docker compose exec php bin/phpunit
docker compose exec php vendor/bin/phpstan analyse
```

Compile assets after changes under `assets/`. Run relevant tests for code changes. Migration generation and execution are separate operations; inspect generated migrations before applying them.

## Optional services

```bash
docker compose --profile gateway up -d relay-gateway
docker compose logs --tail=100 relay-gateway
```

Set `RELAY_GATEWAY_ENABLED` in the application environment when routing through the gateway. Optional profile services also require their own configuration; starting a profile alone does not configure credentials or public URLs.

## Troubleshooting and references

- Queue delays: inspect `messenger:stats` and the matching consumer's logs. [Workers and Queues](../Processes/workers.md) lists the isolated transports.
- Relay connectivity or hydration: inspect strfry and worker-relay logs. See [Relay Setup](../Strfry/relay-setup.md).
- Relay disk usage: use [Storage Maintenance](../Strfry/storage-maintenance.md), including a backup before deletion.
- CPU, memory, compression or Mercure reconnects: review [Runtime Configuration](../Deployment/runtime-tuning.md).
- Scheduled work: check [Cron Processing](../Cron/cron-processing.md).
- Production restarts and migrations: use the explicit Compose files and environment from [Deployment Validation](deployment-validation.md).

Environment values such as `DATABASE_URL`, Redis credentials, Mercure secrets, `SERVER_NAME`, and relay URLs must agree across the services that use them. Keep credentials in the deployment's environment management rather than copying example secrets into documentation.
