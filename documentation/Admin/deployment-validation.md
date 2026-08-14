# Production Deployment Validation

Checklist for validating a production deploy. Run commands inside Docker unless the command explicitly runs on the host.

## Before Deploy

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config
```

Check:

- Required secrets are set and no placeholder values remain.
- `APP_ENV=prod` for production services.
- `SERVER_NAME`, `BASE_DOMAIN`, `MERCURE_URL`, and `MERCURE_PUBLIC_URL` match the deployment.
- Redis settings point at the intended production Redis service.
- Database version and `DATABASE_URL` agree.
- Production-only profiles and public ports are intentional.

## Build

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local build php
```

Check:

- Composer runs with `--no-dev` in the production stage.
- The image builds without baking local `.env.*.local` secrets.
- `asset-map:compile` and cache warmup complete during the image build.

## Deploy

```bash
./scripts/deploy-php.sh
```

Or manually recreate the app services:

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --force-recreate php worker worker-relay worker-profiles cron
```

Include optional profile services such as `relay-gateway`, `mcp`, or Essayist only when enabled for that environment.

## Database

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console doctrine:migrations:migrate --no-interaction

docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console doctrine:schema:validate
```

Back up production data before migrations that update many rows.

## Service Health

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs --tail=100 php
```

Check:

- `php` is healthy.
- Workers are up and not restarting.
- Cron is running.
- No startup errors, missing env vars, or repeated connection failures appear in logs.

## Smoke Tests

```bash
curl -I https://yourdomain.example/
curl -I https://yourdomain.example/up
curl -I https://yourdomain.example/articles
```

Also verify:

- Login works.
- Article lists render from cache or database.
- Publishing/signing paths work for the enabled signer modes.
- Mercure/SSE updates work where expected.
- Optional relay endpoints upgrade to WebSocket where expected.

## After Deploy

- Watch logs for 15-30 minutes.
- Check worker queue depth and Redis connectivity.
- Check PostgreSQL CPU/locks if migrations or backfills ran.
- Confirm admin relay/gateway pages if those services are enabled.