# Complete Setup Guide

This guide covers everything you need to set up Decent Newsroom for both **local development** and **production deployment**.

## Table of Contents

1. [Quick Start (Local Development)](#quick-start-local-development)
2. [Environment Variables Reference](#environment-variables-reference)
3. [Production Deployment](#production-deployment)
4. [Services Overview](#services-overview)
5. [Development Commands](#development-commands)
6. [Troubleshooting](#troubleshooting)

---

## Quick Start (Local Development)

### 1. Clone the Repository

```bash
git clone https://github.com/decent-newsroom/newsroom.git
cd newsroom
```

### 2. Create Environment File

Copy the distribution file and customize if needed:

```bash
cp .env.dist .env
```

The default `.env.dist` file contains sensible defaults for local development. **You can run the project without changes for basic testing.**

### 3. Build and Start the Containers

```bash
# Build the images (first time or after Dockerfile changes)
docker compose build

# Start all services
docker compose up -d

# View logs (optional)
docker compose logs -f
```

### 4. Access the Application

- **Application**: https://localhost:8443
- **Nostr Relay**: ws://localhost:7777 (development only)

> **Note**: You'll see a browser security warning because the local HTTPS certificate is self-signed. This is normal for development.

> **Tip**: If ports 8080/8443 are already in use, you can change them in your `.env` file or override them inline:
> ```bash
> HTTP_PORT=9080 HTTPS_PORT=9443 docker compose up -d
> ```

### Stopping the Application

```bash
docker compose down
```

---

## Environment Variables Reference

### Legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Safe to use default value for local development |
| ⚠️ | Should change for production, but default works locally |
| 🔒 | **Must change** for production (security sensitive) |

---

### Symfony / Application

| Variable | Default | Change? | Description |
|----------|---------|---------|-------------|
| `APP_ENV` | `dev` | ⚠️ | Application environment. Use `dev` locally, `prod` for production |
| `APP_SECRET` | `9e287f1ad...` | 🔒 | Secret key for CSRF tokens, cookies, etc. **Must be unique in production** |
| `SERVER_NAME` | `localhost` | ⚠️ | Your domain name. Use `localhost` locally, `yourdomain.com` in production |
| `HTTP_PORT` | `8080` | ✅ | HTTP port. Default 8080 avoids conflicts locally. Use `80` in production |
| `HTTPS_PORT` | `8443` | ✅ | HTTPS port. Default 8443 avoids conflicts locally. Use `443` in production |
| `HTTP3_PORT` | `8443` | ✅ | HTTP/3 (QUIC) port. Should match HTTPS_PORT |
| `TRUSTED_PROXIES` | `127.0.0.0/8,...` | ✅ | IP ranges of trusted reverse proxies |
| `TRUSTED_HOSTS` | `.+` | ⚠️ | Regex pattern of allowed hostnames |

**Generating a secure APP_SECRET:**
```bash
# Linux/Mac
openssl rand -hex 32

# Or use PHP
php -r "echo bin2hex(random_bytes(32));"
```

---

### Database (PostgreSQL)

| Variable | Default | Change? | Description |
|----------|---------|---------|-------------|
| `POSTGRES_DB` | `newsroom_db` | ✅ | Database name |
| `POSTGRES_USER` | `dn_user` | ✅ | Database username |
| `POSTGRES_PASSWORD` | `password` | 🔒 | Database password. **Must be strong in production** |
| `POSTGRES_VERSION` | `17` | ✅ | PostgreSQL version |
| `POSTGRES_CHARSET` | `utf8` | ✅ | Database character set |
| `DATABASE_URL` | *(composed)* | ✅ | Full connection string (auto-composed from above variables) |

**Example strong password generation:**
```bash
openssl rand -base64 24
```

---

### Redis (Message Queue)

| Variable | Default | Change? | Description |
|----------|---------|---------|-------------|
| `REDIS_HOST` | `redis` | ⚠️ | Redis hostname. Use `redis` (Docker service name) locally |
| `REDIS_PORT` | `6379` | ✅ | Redis port |
| `REDIS_PASSWORD` | `r_password` | 🔒 | Redis password. **Must be strong in production** |
| `MESSENGER_TRANSPORT_DSN` | *(composed)* | ✅ | Full Redis connection string for Symfony Messenger |

**Local vs Production:**
- **Local**: Use the `redis` Docker service (included in `compose.override.yaml`)
- **Production**: Either use an external Redis server or add Redis to `compose.prod.yaml`

---

### Mercure (Real-time Updates)

| Variable | Default | Change? | Description |
|----------|---------|---------|-------------|
| `MERCURE_URL` | `https://php/.well-known/mercure` | ✅ | Internal Mercure hub URL |
| `MERCURE_PUBLIC_URL` | `https://${SERVER_NAME}/.well-known/mercure` | ✅ | Public Mercure URL for clients |
| `MERCURE_JWT_SECRET` | `!ChangeThis...` | 🔒 | JWT secret for Mercure authentication |
| `MERCURE_PUBLISHER_JWT_KEY` | `!NotSoSecret...` | 🔒 | Publisher JWT key |
| `MERCURE_SUBSCRIBER_JWT_KEY` | `!NotSoSecret...` | 🔒 | Subscriber JWT key |
| `MERCURE_PUBLISHER_JWT_ALG` | `HS256` | ✅ | JWT algorithm for publishers |
| `MERCURE_SUBSCRIBER_JWT_ALG` | `HS256` | ✅ | JWT algorithm for subscribers |

**Generating secure Mercure keys:**
```bash
# Generate a 256-bit key
openssl rand -hex 32
```

---

### Nostr Relay

| Variable | Default | Change? | Description |
|----------|---------|---------|-------------|
| `RELAY_DOMAIN` | `relay.localhost` | ⚠️ | Domain for the Nostr relay. Use `relay.yourdomain.com` in production |
| `NOSTR_DEFAULT_RELAY` | `ws://strfry:7777` | ✅ | Internal relay WebSocket URL (use Docker service name) |
| `RELAY_UPSTREAMS` | `"wss://relay.snort.social ..."` | ✅ | Space-separated list of upstream relays to sync from |
| `RELAY_DAYS_ARTICLES` | `7` | ✅ | Days of articles to sync from upstream |
| `RELAY_DAYS_THREADS` | `3` | ✅ | Days of threads to sync from upstream |

---

### Elasticsearch (Optional)

| Variable | Default | Change? | Description |
|----------|---------|---------|-------------|
| `ELASTICSEARCH_ENABLED` | `false` | ✅ | Enable/disable Elasticsearch. Use database queries when `false` |
| `ELASTICSEARCH_HOST` | `localhost` | ⚠️ | Elasticsearch server hostname |
| `ELASTICSEARCH_PORT` | `9200` | ✅ | Elasticsearch port |
| `ELASTICSEARCH_USERNAME` | `elastic` | ⚠️ | Elasticsearch username |
| `ELASTICSEARCH_PASSWORD` | `your_password` | 🔒 | Elasticsearch password |
| `ELASTICSEARCH_INDEX_NAME` | `articles` | ✅ | Index name for articles |
| `ELASTICSEARCH_USER_INDEX_NAME` | `users` | ✅ | Index name for users |

> **Note**: Elasticsearch is optional. The application works without it using database queries instead.

---

## Production Deployment

### 1. Clone the Repository on Your Server

```bash
ssh root@your-server-ip
git clone https://github.com/decent-newsroom/newsroom.git
cd newsroom
```

### 2. Create Production Environment File

Create `.env.prod.local` with your production values:

```bash
# .env.prod.local - Production secrets (DO NOT COMMIT)

# Application
APP_ENV=prod
APP_SECRET=<generate-with-openssl-rand-hex-32>
SERVER_NAME=yourdomain.com
TRUSTED_HOSTS=^(yourdomain\.com|relay\.yourdomain\.com)$

# Database
POSTGRES_DB=newsroom_db
POSTGRES_USER=newsroom
POSTGRES_PASSWORD=<generate-strong-password>

# Redis (if using external Redis, otherwise configure in compose.prod.yaml)
REDIS_HOST=your-redis-host
REDIS_PASSWORD=<your-redis-password>

# Mercure
MERCURE_JWT_SECRET=<generate-with-openssl-rand-hex-32>
MERCURE_PUBLISHER_JWT_KEY=<generate-with-openssl-rand-hex-32>
MERCURE_SUBSCRIBER_JWT_KEY=<generate-with-openssl-rand-hex-32>

# Nostr Relay
RELAY_DOMAIN=relay.yourdomain.com
NOSTR_DEFAULT_RELAY=ws://strfry:7777
```

### 3. Configure DNS

Create these DNS A records pointing to your server's IP:
- `yourdomain.com` → `<server-ip>`
- `relay.yourdomain.com` → `<server-ip>`

### 4. Build and Deploy

```bash
# Build production images
docker compose -f compose.yaml -f compose.prod.yaml build --no-cache

# Start services
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --wait

```

### 5. Verify Deployment

```bash
# Check service status
docker compose -f compose.yaml -f compose.prod.yaml ps

# View logs
docker compose -f compose.yaml -f compose.prod.yaml logs -f
```

Your application should now be available at `https://yourdomain.com` with automatic TLS certificates from Let's Encrypt.

---

## Services Overview

### Docker Services

| Service | Description | Ports (Dev) | Ports (Prod) |
|---------|-------------|-------------|--------------|
| `php` | FrankenPHP (Symfony app + Caddy) | 80, 443 | 80, 443 |
| `database` | PostgreSQL database | 5432 (internal) | internal only |
| `redis` | Redis message queue | 6379 | depends on setup |
| `worker` | Symfony Messenger consumer | none | none |
| `strfry` | Nostr relay | 7777 | internal only |
| `cron` | Scheduled tasks | none | none |
| `article_hydration_worker` | Article sync from relay | none | none |

### Compose Files

| File | Purpose |
|------|---------|
| `compose.yaml` | Base configuration, shared between environments |
| `compose.override.yaml` | Development overrides (auto-loaded by Docker Compose) |
| `compose.prod.yaml` | Production overrides (must be specified explicitly) |

**Development command:**
```bash
docker compose up -d  # Automatically uses compose.yaml + compose.override.yaml
```

**Production command:**
```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

---

## Development Commands

All commands below should be run from the project root. They execute inside the PHP container.

### General Container Commands

```bash
# Enter PHP container shell
docker compose exec php bash

# Run any Symfony console command
docker compose exec php bin/console <command>

# Clear Symfony cache
docker compose exec php bin/console cache:clear

# View all running containers
docker compose ps

# View real-time logs for a service
docker compose logs -f php
```

### Asset Management

This project uses **Symfony AssetMapper** and **Stimulus** for JavaScript assets. There is no npm/webpack build step required.

```bash
# Add a new JavaScript package (instead of editing importmap.php directly)
docker compose exec php bin/console importmap:require <package-name>
# Example: Add Bootstrap
docker compose exec php bin/console importmap:require bootstrap

# Compile assets (run after changing JS files in assets/)
docker compose exec php bin/console asset-map:compile

# List all mapped assets
docker compose exec php bin/console debug:asset-map

# List Stimulus controllers
docker compose exec php bin/console debug:stimulus
```

> **When to run `asset-map:compile`:**
> - After modifying any JavaScript files in `assets/`
> - After adding new Stimulus controllers
> - After adding packages with `importmap:require`
> - Before deploying to production

### User Management

```bash
# Elevate a user to admin role
docker compose exec php bin/console user:elevate <npub> ROLE_ADMIN

# Example: Make yourself an admin
docker compose exec php bin/console user:elevate npub1abc123... ROLE_ADMIN
```

**Available roles:**
- `ROLE_USER` - Default role for all users, added in the background on login
- `ROLE_ADMIN` - Full administrative access

### Database Commands

```bash
# Run database migrations
docker compose exec php bin/console doctrine:migrations:migrate

# Generate a new migration after entity changes
docker compose exec php bin/console doctrine:migrations:diff

# View migration status
docker compose exec php bin/console doctrine:migrations:status

# Execute raw SQL query
docker compose exec php bin/console doctrine:query:sql "SELECT * FROM users LIMIT 5"
```

### Debugging

```bash
# List all routes
docker compose exec php bin/console debug:router

# Show container services
docker compose exec php bin/console debug:container

# Show environment variables
docker compose exec php bin/console debug:dotenv

# Show event listeners
docker compose exec php bin/console debug:event-dispatcher
```

### Getting Help

- Check the [troubleshooting guide](troubleshooting.md)
- Review [production deployment docs](production.md)
- Check [relay configuration](relay-production.md)

---

## Troubleshooting

### Port Already in Use

If ports 8080/8443 are already in use:

```bash
# Find what's using the port
netstat -tulpn | grep :8443  # Linux
netstat -ano | findstr :8443  # Windows

# Use different ports
HTTP_PORT=9080 HTTPS_PORT=9443 docker compose up -d
```

### Database Connection Failed

```bash
# Check database is running
docker compose ps database

# View database logs
docker compose logs database

# Test connection from PHP container
docker compose exec php bin/console doctrine:query:sql "SELECT 1"
```

### Certificate Issues in Production

```bash
# Check Caddy logs for Let's Encrypt errors
docker compose -f compose.yaml -f compose.prod.yaml logs php | grep -i certificate

# Ensure DNS is propagated
dig yourdomain.com
```

### Relay Not Responding

```bash
# Check strfry is running
docker compose ps strfry

# View relay logs
docker compose logs strfry

# Test internal connectivity
docker compose exec php curl http://strfry:7777
```

### Container Won't Start

```bash
# View detailed logs
docker compose logs --tail=50 <service-name>

# Rebuild from scratch
docker compose down -v
docker compose build --no-cache
docker compose up -d
```

---

## Quick Reference: Minimum Production Changes

At minimum, you **must** change these values for production:

```bash
# Security-critical - MUST change
APP_SECRET=<new-random-value>
POSTGRES_PASSWORD=<strong-password>
MERCURE_JWT_SECRET=<new-random-value>
MERCURE_PUBLISHER_JWT_KEY=<new-random-value>
MERCURE_SUBSCRIBER_JWT_KEY=<new-random-value>
REDIS_PASSWORD=<strong-password>  # if using Redis

# Environment-specific - MUST change
APP_ENV=prod
SERVER_NAME=yourdomain.com
RELAY_DOMAIN=relay.yourdomain.com
```

Everything else can use defaults initially and be customized as needed.
