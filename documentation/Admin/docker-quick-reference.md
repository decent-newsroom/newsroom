# Docker Compose Quick Reference Guide

Fast reference for common Docker operations in the Decent Newsroom.

## Development (Local)

### Get Started
```bash
# Start all services (includes Redis, database, strfry)
docker compose up -d

# Watch logs
docker compose logs -f php

# Rebuild after code changes
docker compose build php
docker compose up -d php
```

### Database
```bash
# Create migration after schema changes
docker compose exec php bin/console doctrine:migrations:diff

# Apply migrations
docker compose exec php bin/console doctrine:migrations:migrate

# Drop and recreate schema (dev only!)
docker compose exec php bin/console doctrine:schema:drop --force
docker compose exec php bin/console doctrine:schema:create
```

### Assets
```bash
# Recompile after CSS/JS changes
docker compose exec php bin/console asset-map:compile

# Add new JS package
docker compose exec php bin/console importmap:require <package-name>
```

### Cache & Warmup
```bash
# Clear cache
docker compose exec php bin/console cache:clear

# Warmup cache
docker compose exec php bin/console cache:warmup
```

### Testing
```bash
# Run all tests
docker compose exec php bin/phpunit

# Run specific test
docker compose exec php bin/phpunit tests/Unit/SomeTest.php

# Run with coverage
docker compose exec php bin/phpunit --coverage-html=var/coverage
```

### Debugging
```bash
# PHP shell
docker compose exec php bin/console tinker

# Tail logs in real-time
docker compose logs -f php

# Inspect service state
docker compose ps
docker compose exec php redis-cli -h redis ping
docker compose exec php pg_isready -h database -U app
```

---

## Production (via .env.prod.local)

### First-Time Production Setup

```bash
# 1. Validate configuration
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config

# 2. Build image
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local build php

# 3. Start infrastructure
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d database redis strfry

# 4. Run database migrations
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console doctrine:migrations:migrate --no-interaction

# 5. Deploy application
./scripts/deploy-php.sh

# 6. Verify (use checklist)
documentation/Admin/deployment-validation.md
```

### Subsequent Deployments

```bash
# Safe PHP-only deployment (doesn't touch database, Redis, relays)
./scripts/deploy-php.sh

# Or manually:
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d \
  php worker worker-relay worker-profiles relay-gateway cron
```

### Production Troubleshooting

```bash
# Check if services are healthy
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps

# View logs
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs --tail=50 php

# Database connectivity check
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console dbal:run-sql "SELECT NOW()"

# Redis connectivity check
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  redis-cli -h redis -p 6379 -a ${REDIS_PASSWORD} ping

# Metrics
curl http://localhost:2019/metrics | grep frankenphp
```

### Production Database Operations

```bash
# Backup before migration
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec database \
  pg_dump -U app app | gzip > backup_$(date +%s).sql.gz

# Run migrations
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console doctrine:migrations:migrate --no-interaction

# Validate schema
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console doctrine:schema:validate
```

### Restart a Single Service (Production)

```bash
# Graceful restart
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local restart php

# Force recreate (cleaner for config changes)
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --force-recreate php
```

### Stop Production Services (e.g., for maintenance)

```bash
# Stop everything
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local down

# Stop only PHP (keep database/Redis/strfry running)
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local stop php worker worker-relay worker-profiles relay-gateway

# Stop and remove volumes (DESTRUCTIVE!)
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local down -v
```

---

## Common Workflows

### Update Application Code + Database Schema

```bash
# Development
docker compose exec php bin/console doctrine:migrations:diff
docker compose build && docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate

# Production
./scripts/deploy-php.sh
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console doctrine:migrations:migrate --no-interaction
```

### Enable Essayist Profile (Production)

```bash
# Add to .env.prod.local:
# ESSAYIST_POLICY_TOKEN=your_secret
# ESSAYIST_RELAY_PUBLIC_URL=wss://essayist.yourdomain.com

# Deploy with essayist profile
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  --profile essayist up -d --force-recreate strfry-essayist essayist-gateway

# Or via deploy script (if you have the essayist profile pre-configured)
./scripts/deploy-php.sh
```

### Enable Relay Gateway (Production)

```bash
# Add to .env.prod.local:
# RELAY_GATEWAY_ENABLED=true

# Deploy
./scripts/deploy-php.sh

# Verify
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bin/console app:relay-gateway:status
```

### Scale Workers

```bash
# To increase message processing capacity without rebuilding:
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --scale worker=3
```

### Monitor Metrics in Real-Time

```bash
# From host (if metrics endpoint is accessible)
while true; do curl -s http://localhost:2019/metrics | grep frankenphp_worker; sleep 5; done

# From inside container
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec php \
  bash -c 'while true; do curl -s http://localhost:2019/metrics | grep frankenphp_worker; sleep 5; done'
```

---

## Environment Variable Cheat Sheet

### Development (.env)
```bash
APP_ENV=dev
DATABASE_URL=postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8
REDIS_PASSWORD=r_password
MERCURE_JWT_SECRET=!ChangeThisMercureHubJWTSecretKey!
SERVER_NAME=localhost,*.localhost
RELAY_DOMAIN=relay.localhost
```

### Production (.env.prod.local) — REQUIRED
```bash
APP_ENV=prod
APP_SECRET=<generate-256-char-random-string>
DATABASE_URL=postgresql://app:<strong-password>@database:5432/app?serverVersion=16&charset=utf8
POSTGRES_PASSWORD=<strong-password>
REDIS_PASSWORD=<strong-redis-password>
MERCURE_JWT_SECRET=<generate-strong-jwt-secret>
MERCURE_PUBLIC_URL=https://yourdomain.com/.well-known/mercure
SERVER_NAME=yourdomain.com,www.yourdomain.com,*.yourdomain.com
TRUSTED_HOSTS=yourdomain.com
RELAY_DOMAIN=relay.yourdomain.com
```

---

## Docker Compose File Structure

```
compose.yaml
  ├─ Services shared between dev & prod
  ├─ Base configs for php, database, workers, cron, strfry, redis
  └─ Soft defaults (can be overridden)

compose.prod.yaml
  ├─ Production-specific overrides
  ├─ Required secrets (:?VAR must be set)
  ├─ FrankenPHP worker tuning
  ├─ Port isolation
  └─ Essayist profile auto-enabled

compose.override.yaml (dev only)
  ├─ Local convenience settings
  ├─ Source code mounts
  ├─ Xdebug configuration
  └─ Dev service tweaks
```

---

## Useful Commands by Role

### Frontend Developer
```bash
docker compose exec php bin/console asset-map:compile
docker compose logs -f php
curl http://localhost:8080/
```

### Backend Developer
```bash
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/phpunit
docker compose exec php bin/console tinker
```

### DevOps / Production Support
```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local logs --tail=100 php
./scripts/deploy-php.sh
curl http://localhost:2019/metrics | head -20
```

### Database Admin
```bash
docker compose exec database psql -U app -d app
docker compose exec database pg_dump -U app app > backup.sql
docker compose exec php bin/console doctrine:migrations:status
```

### API / Relay Tester
```bash
# Query Nostr relay
curl -i -N -H "Connection: Upgrade" -H "Upgrade: websocket" \
  http://localhost:7777

# Check relay healthcheck
docker compose logs strfry | tail -20
```

---

## Troubleshooting by Symptom

### "Connection refused" errors
```bash
# Check if service is running
docker compose ps

# Check healthcheck status
docker compose ps | grep "unhealthy"

# Wait for service
docker compose exec php sleep 10
docker compose logs database | tail -5
```

### High memory usage
```bash
# Check memory limits
docker compose stats

# Check PHP memory config
docker compose exec php php -i | grep memory_limit

# Check if workers are recycling properly
docker compose logs worker | grep -i "time.*limit\|exit\|restart"
```

### Slow requests
```bash
# Check FrankenPHP metrics
curl http://localhost:2019/metrics | grep -E "frankenphp_worker|request_duration"

# Check database slow queries
docker compose exec database psql -U app -d app -c "SELECT * FROM pg_stat_statements LIMIT 10;"

# Check Redis latency
docker compose exec redis redis-cli LATENCY DOCTOR
```

### Asset loading issues
```bash
# Recompile assets
docker compose exec php bin/console asset-map:compile

# Check public/assets/ exists
docker compose exec php ls -la public/assets/ | head -20

# Check Caddy serving assets
curl -I http://localhost:8080/assets/app.js
```

---

## Performance Tuning

### Increase Worker Count (Handle More Concurrent Requests)
```bash
# In .env.prod.local:
FRANKENPHP_WORKERS=8  # Was 4

# Redeploy
./scripts/deploy-php.sh
```

### Increase Thread Count Per Worker (Better for Blocking I/O)
```bash
# In .env.prod.local:
FRANKENPHP_MAX_THREADS=24  # Was 12

# Redeploy
./scripts/deploy-php.sh
```

### Increase Memory Limit (For Large Batch Operations)
```bash
# In .env.prod.local:
PHP_MEMORY_LIMIT=512M  # Was 256M
GOMEMLIMIT=1024MiB    # Was 768MiB

# Redeploy
./scripts/deploy-php.sh
```

### Check Before Tuning
```bash
curl http://localhost:2019/metrics | grep -E "caddy_http_request_duration|frankenphp_worker"
```

---

## Security Reminders

- ✓ Never commit `.env.prod.local` to version control
- ✓ Use strong random secrets for `APP_SECRET`, `MERCURE_JWT_SECRET`
- ✓ Rotate `REDIS_PASSWORD` and `POSTGRES_PASSWORD` regularly
- ✓ Keep Docker image build secure (verify SWC binary checksums)
- ✓ Monitor container logs for suspicious activity
- ✓ Regularly update base images and dependencies


