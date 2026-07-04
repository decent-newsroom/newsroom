# Docker Compose Production Deployment Validation Checklist

This document provides a comprehensive checklist for validating a production deployment of the Decent Newsroom with the improved Docker/Compose/FrankenPHP configuration.

## Pre-Deployment: Static Validation

### 1. Configuration Integrity

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config
```

**Checks:**
- [ ] No placeholders like `!ChangeMe!`, `!ChangeThisMercureHubJWTSecretKey!`, `r_password`, or `changeme` appear
- [ ] All `${VAR:?VAR must be set}` variables are present and non-empty
- [ ] PostgreSQL version is consistent (either all 16 or all 17, no mismatch)
- [ ] Redis is external (not defined as a Compose service, accessed via `REDIS_HOST` IP/hostname)
- [ ] `REDIS_PASSWORD` is set in `.env.prod.local`
- [ ] strfry images are pinned to `v1.30.1` (or newer), not `:latest`
- [ ] Production port mappings are correct:
  - PHP: 80/443/443(udp) exposed (or removed if behind proxy)
  - strfry: no port exposed in prod (internal-only)
  - relay-gateway: accessible via Caddy reverse proxy, not directly exposed
- [ ] `SERVER_NAME` includes at least the public domain + `php:80` + `localhost`
- [ ] `MERCURE_PUBLIC_URL` is set to the external-facing URL
- [ ] `MERCURE_URL` points to internal service URL (`http://php/.well-known/mercure`)
- [ ] `TRUSTED_HOSTS` is set to actual domain(s), NOT `.+` wildcard
- [ ] `TRUSTED_PROXIES` matches your reverse proxy network (if applicable)

### 2. Dockerfile Compliance

```bash
cat Dockerfile | grep -E "(COPY|composer|--no-dev|dump-env prod --empty)"
```

**Checks:**
- [ ] Composer layer caching works: `composer.* symfony.*` are copied before other source
- [ ] Composer install uses `--no-dev --no-scripts --no-autoloader` in first RUN
- [ ] Secrets are NOT baked in: `composer dump-env prod --empty` (empty, not prod values)
- [ ] Production stage uses `frankenphp_prod` target
- [ ] SWC binary download has retry logic
- [ ] Base packages do NOT include `git`, `bash`, `libnss3-tools`, `cron`
- [ ] PHP extensions include: composer, opcache, apcu, intl, zip, gmp, gd, redis, pcntl, pdo_pgsql

### 3. .dockerignore Checks

```bash
grep -E "(\.env|vendor|var/|node_modules|compose\.)" .dockerignore
```

**Checks:**
- [ ] `.env.*.local` files are ignored
- [ ] `vendor/` is ignored
- [ ] `var/` (cache, logs) is ignored
- [ ] `compose*.yaml` files are ignored
- [ ] `public/assets/` and `public/build/` are ignored
- [ ] `.git/` and related VCS files are ignored

---

## Build & Deployment Steps

### 4. Build Docker Image

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local build php
```

**Checks:**
- [ ] Build completes without errors
- [ ] Build output shows `--no-dev` in composer install
- [ ] No references to development packages (xdebug, etc.) in prod stage
- [ ] Image size is reasonable (< 500MB is typical for PHP 8.3 + extensions)

### 5. Start Infrastructure (if not already running)

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d database redis strfry
```

**Checks:**
- [ ] Database becomes healthy within 120 seconds
- [ ] Redis healthcheck passes (shows `PONG` response)
- [ ] strfry starts cleanly (check `docker logs`)

### 6. Deploy PHP & Workers

```bash
./scripts/deploy-php.sh
```

Or manually:

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --force-recreate php worker worker-relay worker-profiles relay-gateway cron
```

**Checks:**
- [ ] All services start without restart loops
- [ ] PHP service reaches `healthy` status within 60 seconds

---

## Post-Deployment: Functional Validation

### 7. PHP Service Health

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs php | head -100
```

**Checks:**
- [ ] FrankenPHP worker mode is active (`using worker mode`)
- [ ] No PHP errors or deprecation warnings in startup
- [ ] Symfony cache is warm (no `cache:clear` failures)
- [ ] Caddy listens on port 80/443 as configured
- [ ] `FRANKENPHP_NUM_THREADS` and `FRANKENPHP_MAX_THREADS` are honored
- [ ] Healthcheck endpoint is reachable: `curl http://localhost:8080/up`

### 8. Database Connectivity

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console dbal:run-sql "SELECT NOW()"
```

**Checks:**
- [ ] Query returns current timestamp
- [ ] No connection errors or timeout warnings
- [ ] Connection pooling is active (visible in PSR connection info)

### 9. Redis Connectivity

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  redis-cli -h redis -p 6379 -a ${REDIS_PASSWORD} ping
```

**Checks:**
- [ ] Response is `PONG`
- [ ] No authentication failures

### 10. Worker Health

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps
```

**Checks:**
- [ ] All worker services are in `Up` or `Up (healthy)` status
- [ ] No continuous restarts (exit code 0, no restart count increasing)
- [ ] `worker` is consuming messages (check log volume)
- [ ] `worker-relay` is subscribed to strfry (logs show WebSocket tunnel)
- [ ] `worker-profiles` is running profile refresh loop
- [ ] `cron` is running in foreground (check that it's `Up`)
- [ ] `relay-gateway` is healthy (if enabled)

### 11. Relay Gateway (if enabled)

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  exec relay-gateway php bin/console app:relay-gateway:status
```

**Checks:**
- [ ] Gateway is active
- [ ] Heartbeat timestamp is recent (< 60 seconds old)
- [ ] Connection pool size is reasonable
- [ ] No excessive errors in logs

### 12. Essayist Gateway (if enabled)

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  exec essayist-gateway curl http://localhost:7781/health
```

**Checks:**
- [ ] HTTP 200 response
- [ ] strfry-essayist relay is connected upstream
- [ ] No AUTH failures in logs

### 13. Application Endpoints

Test via your domain or localhost:

```bash
# Reader page (should be fast)
curl -I https://yourdomain.com/

# Article list (should load from Redis)
curl -I https://yourdomain.com/articles

# Relay WebSocket (should upgrade)
curl -I -H "Upgrade: websocket" https://yourdomain.com/relay || true

# Essayist relay (if enabled)
curl -I -H "Upgrade: websocket" https://essayist.yourdomain.com/ || true

# Healthcheck
curl https://yourdomain.com/up

# Metrics (if exposed and accessible)
curl http://localhost:2019/metrics 2>/dev/null | head -20
```

**Checks:**
- [ ] HTTP 200/101 responses (no 502 Bad Gateway)
- [ ] Response times are low (< 500ms for fast pages)
- [ ] WebSocket upgrades succeed
- [ ] Mercure SSE connections remain active (no reconnect cycling)

---

## Performance & Stability Checks (24-30 minute observation)

### 14. Memory & CPU Usage

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local stats
```

**Checks:**
- [ ] PHP memory usage is stable and < 60% of limit (default 256M)
- [ ] Worker processes use < 40% of memory each
- [ ] CPU usage is steady during normal load (not continuously maxed)
- [ ] No memory leaks (memory should stabilize after warmup)

### 15. Service Logs (no errors expected)

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs --tail=50 php worker
```

**Checks:**
- [ ] No repeated PHP errors or exceptions
- [ ] No Doctrine connection pool exhaustion warnings
- [ ] No Messenger consumer hang or timeout warnings
- [ ] No Redis connection failures
- [ ] No relay WebSocket disconnection loops

### 16. Check for Restart Loops

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps
```

**Checks:**
- [ ] No service is in `Restarting` or `Exited` status
- [ ] Restart count is stable (not incrementing)

---

## Database & Cache State Validation

### 17. Database Integrity

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console doctrine:schema:validate
```

**Checks:**
- [ ] No schema validation errors
- [ ] All migration versions are applied

### 18. Cache Warming

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console cache:warmup --env=prod 2>&1 | grep -i "error\|fail" || echo "Cache OK"
```

**Checks:**
- [ ] No errors during cache warmup
- [ ] Cache directory is writable and volume-persisted

### 19. Asset Map Compilation

```bash
ls -lh public/assets/ | head -20
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console asset-map:compile --env=prod --no-interaction
```

**Checks:**
- [ ] `public/assets/` contains fingerprinted files
- [ ] No errors during recompilation
- [ ] Cache-busting works (new filenames after recompile)

---

## Security Checks

### 20. Secret Exposure

```bash
docker history ${COMPOSE_PROJECT_NAME:-newsroom}-php | grep -E "ENV|ARG" | grep -v "APP_ENV"
```

**Checks:**
- [ ] No `APP_SECRET`, `REDIS_PASSWORD`, `POSTGRES_PASSWORD`, `MERCURE_JWT_SECRET` in image history
- [ ] Only `APP_ENV=prod` is baked in

### 21. User Isolation

```bash
docker inspect $(docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps -q php) | grep -A5 '"User"'
```

**Checks:**
- [ ] PHP runs as unprivileged user (not root)

---

## Observability Setup

### 22. Enable Metrics (if not already running)

Caddy/FrankenPHP metrics are enabled in updated Caddyfile. Expose on internal-only network:

```bash
# Check metrics are available (from inside container or proxy)
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  curl -s http://localhost:2019/metrics | head -20
```

**Checks:**
- [ ] Metrics endpoint responds
- [ ] Core metrics are present: `frankenphp_worker_*`, `caddy_*`

### 23. Log Aggregation Setup

If using a log aggregator (e.g., ELK, Datadog):

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs --timestamps php | head -5
```

**Checks:**
- [ ] Logs are parseable with ISO 8601 timestamps
- [ ] Log format is consistent across services

---

## Recovery & Backup Validation

### 24. Database Backup

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec database \
  pg_dump -U app app | gzip > backup_$(date +%s).sql.gz
```

**Checks:**
- [ ] Backup completes without errors
- [ ] Backup file is > 1MB (indicates non-empty dump)

### 25. Volume Persistence

Verify critical volumes are persisted to disk (not ephemeral):

```bash
docker volume ls | grep -E "caddy_data|database_data|redis_data|strfry_data"
```

**Checks:**
- [ ] All volumes are present and have driver `local`
- [ ] Volumes persist across container restart

---

## Final Checklist

Before production traffic:

- [ ] All 25 validation sections pass
- [ ] Load test passes (simulate production traffic for 5-10 minutes)
- [ ] Failover tested (restart php service, confirm recovery < 30 seconds)
- [ ] Rollback plan documented and verified
- [ ] On-call alerts are configured
- [ ] Monitoring dashboards are set up
- [ ] Team is aware of deployment and can respond to alerts

---

## Troubleshooting Reference

| Issue | Root Cause | Solution |
|-------|------------|----------|
| PHP 502 on app start | Cache/asset compilation failure | `docker logs php` → check for cache/compile errors |
| Connection pool exhausted | Worker count too high, DB connections leaked | Reduce `FRANKENPHP_WORKERS`, check app for DB handle leaks |
| Redis connection refused | Redis not running or password mismatch | Verify `REDIS_PASSWORD` in `.env.prod.local` vs Redis config |
| Strfry relay timeout | Long event processing | Check `docker logs strfry` for write-policy errors |
| Mercure SSE reconnect cycling | Gzip buffering on SSE path | Verify Caddyfile excludes Mercure from encoding |
| Memory leak in workers | Long-running worker state not cleaned | Check if services have `--time-limit` and are recycling properly |
| Essayist gateway AUTH failures | NIP-42 challenge not signing | Verify relay-gateway can reach redis and php services |
| Assets not caching | Fingerprint filename not changing | Rerun `asset-map:compile`, verify `public/assets/` is accessible |

---

## Rollback Procedure

If deployment fails or causes issues:

```bash
# 1. Stop problematic services
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local stop php

# 2. Restore previous image (tag or re-pull)
docker image rm ${COMPOSE_PROJECT_NAME:-newsroom}-php:previous || true
docker pull ${REGISTRY}/${COMPOSE_PROJECT_NAME:-newsroom}-php:previous
docker tag ${REGISTRY}/${COMPOSE_PROJECT_NAME:-newsroom}-php:previous \
  ${COMPOSE_PROJECT_NAME:-newsroom}-php

# 3. Redeploy
./scripts/deploy-php.sh

# 4. Verify
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs php | tail -50
```

---

## Notes

- **Worker Mode Safety**: Symfony 7.4 + FrankenPHP worker mode requires careful auditing for state leaks. See `AGENTS.md` section "FrankenPHP-specific improvements" → "Audit for worker-mode state leaks".
- **Slow Path Handling**: Consider implementing separate relay-api service if API/relay paths are slow. Current config shares worker pool.
- **Monitoring**: Caddy metrics are now enabled. Expose on internal-only network to avoid security issues.
- **Deployment Frequency**: Use `scripts/deploy-php.sh` for safe deployments. Avoid full `docker compose up -d --build` which restarts infrastructure.



