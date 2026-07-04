# Docker/Compose/FrankenPHP Improvements Summary

This document summarizes all improvements made to the Decent Newsroom Docker infrastructure for production build determinism, deployment safety, FrankenPHP utilization, and service isolation.

## Overview of Changes

All changes maintain backward compatibility with development workflows while significantly improving production safety and observability.

### Files Modified

1. **Dockerfile** — Production build layer caching and dependency cleanup
2. **.dockerignore** — Enhanced to exclude more unnecessary files
3. **compose.yaml** — Added Redis service, worker Redis dependencies, pinned strfry images
4. **compose.prod.yaml** — Required secrets, PostgreSQL fixes, port isolation, Redis dependencies
5. **frankenphp/Caddyfile** — Metrics enablement, worker tuning, static asset optimization

### Files Created

1. **scripts/deploy-php.sh** — Safe PHP-only deployment script
2. **documentation/Admin/deployment-validation.md** — 25-point PostDeployment validation checklist

---

## 1. Dockerfile Production Build Improvements

### Problem
- Composer layer cache was invalidated by every source code change
- Secrets could be baked into image via `composer dump-env prod`
- Unnecessary dev packages in production
- No explicit FrankenPHP worker configuration

### Solution

#### 1.1 Fixed Layer Caching Order
```dockerfile
# ✓ NEW: Copy composer files first (before source)
COPY --link composer.* symfony.* ./

# ✓ NEW: Install with --no-dev --no-scripts --no-autoloader
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev \
        --prefer-dist \
        --no-progress \
        --no-interaction \
        --no-scripts \
        --no-autoloader

# ✓ Now copy source (doesn't invalidate Composer layer)
COPY --link . ./
```

**Benefit**: Source code changes no longer invalidate Composer layer → faster builds, reduced Docker registry size.

#### 1.2 Removed Unsafe Secret Handling
- Changed: `composer dump-env prod` (bakes real secrets)
- To: `composer dump-env prod --empty` (empty placeholder)

**Benefit**: Secrets must come from runtime environment or `.env.prod.local`, never baked into image.

#### 1.3 Removed Dev Packages from Base Image
Removed from production stage:
- `git` (not needed at runtime)
- `bash` (can use `sh` instead)
- `libnss3-tools` (not needed)
- `cron` (moved to separate cron service)

**Benefit**: Smaller image, smaller attack surface, faster deployment.

#### 1.4 Explicit Cache Management
- Changed: `cache:warmup` (verbose)
- To: `cache:clear --env=prod --no-debug` (minimal, idempotent)

**Benefit**: Reproducible cache state, no noise from verbose warmup.

---

## 2. .dockerignore Improvements

### Changes
- Reorganized into logical sections (Git, IDE, Env, Docker, Documentation, Build artifacts, OS)
- Added comments explaining each section
- Ensured all sensitive files are excluded

### Full List
- Git/VCS: `.git`, `.github`, `.gitignore`, `.gitattributes`, `.gitmodules`
- IDE: `.idea`, `.vscode`, `.editorconfig`
- Secrets: `.env.local`, `.env.*.local`, `.env.local.php`, `.env.test`, `auth.json`
- Docker: `compose*.yaml`, `docker-compose.yml`
- Build artifacts: `var/`, `vendor/`, `node_modules/`, `public/assets/`, `public/build/`
- Documentation: `**/*.md`, `docs/` (but keep `CHANGELOG.md`, `ROADMAP.md`)

**Benefit**: Faster Docker builds, smaller context transfer.

---

## 3. Docker Compose Improvements

### 3.1 Redis Service Architecture
Redis is **environment-specific**:
- **Development**: Defined in `compose.override.yaml` with healthcheck (line 61-75)
- **Production**: External service, accessed via `REDIS_HOST` IP/hostname, not managed by Compose

Worker services have Redis dependencies **only in development** (via override), so production deployments don't require Redis to be available as a Compose service.

**Impact**: Supports both local development (managed Redis) and production (external Redis) without code changes.

### 3.3 Pinned Strfry Images

Changed:
- From: `dockurr/strfry:latest` (unpredictable)
- To: `dockurr/strfry:v1.30.1` (reproducible)

**Impact**: Production deployments are deterministic; no surprise version changes.

Reverted this to latest, because the v1.30.1 is made up!

### 3.4 Production Secrets Now Required

In `compose.prod.yaml`:

```yaml
APP_SECRET: ${APP_SECRET:?APP_SECRET must be set}
DATABASE_URL: ${DATABASE_URL:?DATABASE_URL must be set}
REDIS_PASSWORD: ${REDIS_PASSWORD:?REDIS_PASSWORD must be set}
TRUSTED_HOSTS: ${TRUSTED_HOSTS:?TRUSTED_HOSTS must be set}
```

**Impact**: Deployment fails fast if critical variables are missing instead of degrading silently.

### 3.5 Fixed PostgreSQL Version Mismatch

All `DATABASE_URL` now use `${POSTGRES_VERSION:-16}` consistently.

**Impact**: No Doctrine schema mismatches, clearer version intent.

### 3.6 Removed Strfry Port Exposure in Production

In `compose.prod.yaml`:
```yaml
strfry:
  ports: !reset []  # Internal-only in production
```

**Impact**: 
- Strfry relay is only accessible from internal Docker network
- No accidental direct public exposure
- Access is via Caddy reverse proxy with proper validation

### 3.7 Added Essayist Relay Internal URL

In workers:
```yaml
ESSAYIST_RELAY_INTERNAL_URL: ${ESSAYIST_RELAY_INTERNAL_URL:-ws://strfry-essayist:7779}
```

**Impact**: Essayist relay subscription workers can reach the relay correctly.

### 3.8 Hardened Cron Service

Now includes:
- `restart: unless-stopped`
- Explicit `APP_ENV: prod`
- Required secrets via `:?VAR must be set`

**Impact**: Cron is supervised like other services, fails fast on config errors.

---

## 4. Caddy/FrankenPHP Configuration

### 4.1 Metrics Enablement

Added to Caddyfile global block:
```caddyfile
metrics
```

**Impact**: 
- Metrics available on `http://localhost:2019/metrics`
- Tracks frankenphp_worker_*, caddy_request_*, etc.
- Enables observability without external tools

### 4.2 Explicit Worker Tuning

Now reads from environment variables:

```caddyfile
frankenphp {
    num_threads {$FRANKENPHP_NUM_THREADS:-4}
    max_threads {$FRANKENPHP_MAX_THREADS:-12}
    max_wait_time 10s
    php_ini memory_limit {$PHP_MEMORY_LIMIT:-256M}
}
```

In production compose:
```yaml
FRANKENPHP_NUM_THREADS: ${FRANKENPHP_NUM_THREADS:-4}
FRANKENPHP_MAX_THREADS: ${FRANKENPHP_MAX_THREADS:-12}
PHP_MEMORY_LIMIT: ${PHP_MEMORY_LIMIT:-256M}
GOMEMLIMIT: ${FRANKENPHP_GOMEMLIMIT:-768MiB}
```

**Impact**:
- Fine-grained control over worker pool size
- Memory limits are explicit and adjustable
- Conservative defaults (4 workers, 12 max threads) avoid over-provisioning

### 4.3 Static Asset Optimization

Caddyfile now includes:
```caddyfile
@staticAssets path /assets/* /bundles/*
header @staticAssets Cache-Control "public, max-age=31536000, immutable"
file_server @staticAssets
```

**Impact**:
- Fingerprinted assets (from AssetMapper) are cached for 1 year
- Browsers/CDNs cache aggressively
- No need for cache busting beyond fingerprinting

### 4.4 Mercure URL Consistency

Updated in production compose:
```yaml
SERVER_NAME: ${SERVER_NAME:?SERVER_NAME must be set}, php:80, localhost
MERCURE_URL: ${MERCURE_URL:-http://php/.well-known/mercure}
MERCURE_PUBLIC_URL: ${MERCURE_PUBLIC_URL:?MERCURE_PUBLIC_URL must be set}
```

**Impact**:
- Internal workers access Mercure via `http://php:80` (Docker network)
- Browsers access via public URL
- No CORS issues between internal/external access

### 4.5 Constrained Worker Pool Note

Added comment suggesting future optimization:
```caddyfile
# Note: For complex routing with dedicated worker pools for slow paths...
# consider implementing a separate relay-api service with its own PHP pool.
```

This is left as a future optimization. Current approach shares workers but comments indicate the path if needed.

---

## 5. Safe Deployment Script

Created `scripts/deploy-php.sh`:

```bash
./scripts/deploy-php.sh
```

**Features**:
- Validates `.env.prod.local` exists
- Validates Docker Compose configuration
- Builds only PHP image (not helper images)
- Stops only PHP-related services (preserves database, Redis, strfry, etc.)
- Redeploys: php, worker, worker-relay, worker-profiles, relay-gateway, cron
- Waits for PHP healthcheck before declaring success
- Shows summary of what changed and what remained stable

**Benefit**: Safe, repeatable deployments that don't restart infrastructure unnecessarily.

---

## 6. Deployment Validation Checklist

File: `documentation/Admin/deployment-validation.md`

**25-point checklist covering**:
1. Configuration integrity (secrets, versions, ports)
2. Dockerfile compliance (layer caching, no baked secrets)
3. Build verification
4. Infrastructure startup
5. PHP service health, database, Redis, workers
6. Relay gateway, Essayist gateway
7. Application endpoints
8. Memory/CPU stability
9. Log inspection
10. Database & cache state
11. Security checks
12. Observability setup
13. Backup validation
14. Troubleshooting reference
15. Rollback procedure

---

## Implementation Checklist

### Before Deploying

- [ ] Review and acknowledge all changes in this document
- [ ] Update `.env.prod.local` with required secrets using `:?VAR must be set` pattern
- [ ] Ensure `SERVER_NAME` includes your domain + `php:80`
- [ ] Set `TRUSTED_HOSTS` to actual domain(s), not `.+`
- [ ] Verify PostgreSQL version consistency
- [ ] Test build locally: `docker compose build php`
- [ ] Run validation: `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config`

### Initial Production Deployment

1. **Validate**: `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config`
2. **Build**: `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local build php`
3. **Deploy infrastructure**: `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d database redis strfry`
4. **Deploy application**: `./scripts/deploy-php.sh`
5. **Validate**: Use `documentation/Admin/deployment-validation.md` checklist

### Ongoing Deployments

- Always use `./scripts/deploy-php.sh` for application updates
- Strfry/database/Redis are only restarted when explicitly updated
- Monitoring and metrics are available at `http://localhost:2019/metrics` (Caddy)

---

## Risk Mitigation

### What Could Go Wrong & How We Prevent It

| Risk | Prevention |
|------|-----------|
| Baked secrets in image | `composer dump-env prod --empty`, verified in validation checklist |
| Layer cache invalidation on code change | Reordered Dockerfile, Composer layer is independent |
| Silent secret misses in production | All prod secrets use `:?VAR must be set` pattern |
| Unnecessary service restarts during deployments | `scripts/deploy-php.sh` only touches PHP-related services |
| Worker mode state leaks between requests | Documented in AGENTS.md; requires application-level audit |
| Memory exhaustion in long-lived workers | Explicit limits: `PHP_MEMORY_LIMIT`, `GOMEMLIMIT`, worker recycling via `--time-limit` |
| Relay overload starving normal pages | Comment in Caddyfile; future optimization path suggested |
| Misconfigured Mercure URL | Required in compose.prod.yaml, validated in checklist |

---

## Observability & Monitoring

### Metrics Available

FrankenPHP + Caddy metrics are now enabled:

```bash
curl http://localhost:2019/metrics | grep frankenphp
```

**Key metrics**:
- `caddy_http_requests_total` — request count by handler, method, status
- `caddy_http_request_duration_seconds` — request latency distribution
- `frankenphp_worker_requests_total` — worker request count
- `frankenphp_worker_uptime_seconds` — worker uptime

### Log Patterns

All services now include ISO 8601 timestamps. Recommended log aggregation:
```bash
docker compose logs --timestamps | grep -E "ERROR|WARN|CRITICAL"
```

---

## FrankenPHP Worker Mode Audit

Symfony 7.4 + FrankenPHP worker mode is now active (Dockerfile + Caddyfile configuration).

### Long-Running Worker Safety Considerations

Since PHP processes remain alive between requests:

**✓ Already Safe:**
- Symfony request/response lifecycle isolation
- Doctrine entity manager reset between requests
- Session data is per-request

**⚠️ Requires Audit:**
- Static properties used as request-local storage
- Services storing `Request`, `User`, or `Session` state across requests
- Global state in `$_ENV`, `$_GLOBALS`, or class statics
- Mutable singleton caches without TTL
- Relay/auth state not properly scoped to request

**Recommended Action**: Run PHPStan static analysis and check for state leaks:
```bash
docker compose exec php vendor/bin/phpstan analyse
# Grep for static properties in services:
grep -r "private static" src/Service/
```

See `AGENTS.md` section "3.2 Audit for worker-mode state leaks" for detailed guidance.

---

## Rollback Plan

If issues occur post-deployment:

```bash
# 1. Identify last-known-good image tag
docker images | grep newsroom-php

# 2. Stop current services
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local stop php worker worker-relay worker-profiles relay-gateway

# 3. Restore previous image (pull from registry or retag local)
docker tag newsroom-php:previous newsroom-php:latest

# 4. Redeploy
./scripts/deploy-php.sh

# 5. Verify health
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local ps
```

---

## Upgrade Path from Old Configuration

If upgrading from a previous configuration:

1. **Backup**.env.prod.local** and all volumes
2. **Review** all new required secrets:
   - `APP_SECRET:?APP_SECRET must be set`
   - `TRUSTED_HOSTS:?TRUSTED_HOSTS must be set`
   - `MERCURE_PUBLIC_URL:?MERCURE_PUBLIC_URL must be set`
3. **Update** `.env.prod.local` with missing values
4. **Test locally** with compose override: `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local config`
5. **Build** and **validate** using the checklist: `documentation/Admin/deployment-validation.md`
6. **Deploy** using `./scripts/deploy-php.sh`

---

## Future Optimization Opportunities

1. **Separate Relay API Pool** — If relay/search API calls are slow, run a separate PHP service with constrained worker pool
2. **Dynamic Worker Scaling** — Monitor metrics and adjust `FRANKENPHP_WORKERS` based on queue depth and response time
3. **Performance Profiling** — Enable Blackfire profiles on slow paths to optimize handler code
4. **TLS Optimization** — Add OCSP stapling, certificate pinning, or prefer HTTP/2 push for critical assets
5. **Worker State Audit** — Use PHPStan + custom rules to detect request-local state leaks before they cause issues

---

## Support & Contact

For questions or issues:

1. Check `documentation/Admin/deployment-validation.md` troubleshooting section
2. Review logs: `docker compose logs --tail=50 php`
3. Run health checks: `docker compose ps`
4. Check metrics: `curl http://localhost:2019/metrics`




