# Quick Reference

Essential commands and information for working with Decent Newsroom.

## Quick Start

```bash
git clone https://github.com/decent-newsroom/newsroom.git
cd newsroom
cp .env.dist .env
docker compose build
docker compose up -d

# Access: https://localhost:8443
```

## Docker Commands

### Container Management

```bash
docker compose up -d              # Start all services
docker compose down               # Stop all services
docker compose restart php        # Restart a service
docker compose logs -f php        # View logs
docker compose ps                 # Check status
docker compose build              # Rebuild images
```

### Accessing Containers

```bash
docker compose exec php bash                            # PHP container shell
docker compose exec database psql -U app -d app         # Database console
docker compose exec redis redis-cli -a r_password       # Redis CLI
```

### With Relay Gateway

```bash
docker compose --profile gateway up -d                  # Start with gateway
docker compose --profile gateway logs -f relay-gateway  # Gateway logs
```

## Symfony Console Commands

All commands run inside Docker: `docker compose exec php bin/console <command>`

### Database

```bash
bin/console doctrine:migrations:migrate        # Run migrations
bin/console doctrine:migrations:diff           # Create migration from entity changes
bin/console doctrine:migrations:status         # Migration status
bin/console dbal:run-sql "SELECT ..."          # Run SQL
```

### Cache

```bash
bin/console cache:clear                        # Clear all caches
bin/console cache:warmup                       # Warm cache
bin/console cache:pool:clear cache.app         # Clear specific pool
```

### Assets

```bash
bin/console asset-map:compile                  # Compile assets
bin/console debug:asset-map                    # List assets
bin/console importmap:require <package>        # Add JS package
```

### Users & Roles

```bash
bin/console user:elevate <npub> ROLE_ADMIN     # Make admin
bin/console user:elevate <npub> ROLE_RSS       # Grant RSS import access
bin/console user:elevate <npub> ROLE_FEATURED_WRITER  # Featured writer
```

### Workers & Background Tasks

```bash
bin/console app:run-workers                    # Messenger consumers (async + async_low_priority)
bin/console app:run-relay-workers              # Local relay subscriptions
bin/console app:run-profile-workers            # Profile refresh + async_profiles
bin/console app:relay-gateway                  # Persistent relay connection pool
```

### Article & Content Processing

```bash
bin/console app:cache-latest-articles          # Cache latest articles to Redis
bin/console app:cache-latest-highlights        # Cache highlights to Redis
bin/console app:fetch-highlights --limit=200   # Fetch highlights from relays
bin/console app:cache-media-discovery          # Cache media events
bin/console articles:process-html              # Re-process article HTML
```

### Graph Layer

```bash
bin/console dn:graph:audit --fix                        # Audit & repair graph consistency
bin/console dn:graph:backfill-current-records            # Backfill current_record table
bin/console dn:graph:backfill-references                 # Backfill parsed_reference table
bin/console dn:graph:rebuild-record <coordinate>         # Rebuild single coordinate
```

### Elasticsearch (when enabled)

```bash
bin/console fos:elastica:populate              # Reindex all data
```

### Debug

```bash
bin/console debug:router                       # List all routes
bin/console debug:router <route_name>          # Route details
bin/console debug:container                    # List services
bin/console debug:container --env-vars         # Environment variables
```

## Common Tasks

### Create Admin User

```bash
# 1. Log in once (creates user record)
# 2. Run:
docker compose exec php bin/console user:elevate npub1... ROLE_ADMIN
```

### Backup Database

```bash
docker compose exec database pg_dump -U app app > backup.sql
```

### Restore Database

```bash
cat backup.sql | docker compose exec -T database psql -U app -d app
```

### Update Application

```bash
git pull origin main
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/console cache:clear
docker compose restart php worker worker-relay worker-profiles
```

### View Logs

```bash
docker compose logs -f php                     # Application
docker compose logs -f worker                  # Messenger consumers
docker compose logs -f worker-relay            # Relay subscriptions
docker compose logs -f worker-profiles         # Profile workers
docker compose logs --tail=100 php             # Recent entries
docker compose logs php | grep ERROR           # Filter errors
```

## URLs & Endpoints

### Application

```
https://localhost:8443                    Homepage
https://localhost:8443/newsstand         Magazine index
https://localhost:8443/bookshelf         Books
https://localhost:8443/multimedia        Media discovery
https://localhost:8443/highlights        Highlights
https://localhost:8443/forum             Topics / tags
https://localhost:8443/article-editor/create  Article editor
https://localhost:8443/my-content        Authored content and drafts
https://localhost:8443/reading-list      Reading lists
https://localhost:8443/settings          User settings
https://localhost:8443/admin             Admin dashboard
https://localhost:8443/_profiler         Profiler (dev mode)
```

### API & Data

```
/article/d/{slug}                        Article by slug
/article/{naddr}                         Article by Nostr address
/p/{npub}/d/{slug}                       Article by author npub and slug
/e/{identifier}                          Event by naddr/nevent/note
/mag/{slug}/manifest.json                Magazine manifest
/follow-pack/{pubkey}/{dtag}             Follow pack view
/featured-articles                       Featured writers' articles
```

### Admin

```
/admin                                   Dashboard
/admin/relay                             Relay pool & health
/admin/relay/gateway                     Gateway status
/admin/rss                               RSS feed import
/admin/feedback                          Feedback messages
/admin/follow-packs                      Home feed follow packs
/admin/magazines                         Magazine management
/admin/mercure                           Mercure hub diagnostics
/admin/role                              Role management
/admin/unfold                            Unfold sites
/admin/vanity-names                      Vanity names
/admin/analytics                         Visitor analytics
```

## Configuration Files

```
.env                          Environment configuration
compose.yaml                  Docker services (base)
compose.override.yaml          Dev overrides
compose.prod.yaml              Production overrides
config/services.yaml          Service container & relay registry
config/packages/              Bundle configurations
config/routes.yaml            Route definitions
docker/cron/crontab           Cron job schedule
docker/strfry/strfry.conf     Main relay config
docker/strfry/router.conf     Relay router config
```

## Environment Variables

```bash
APP_ENV=dev                              # dev or prod
APP_SECRET=change_me                     # Encryption key
DATABASE_URL=postgresql://...            # PostgreSQL connection
REDIS_HOST=redis                         # Redis host
REDIS_PASSWORD=r_password                # Redis password
NOSTR_DEFAULT_RELAY=ws://strfry:7777     # Local relay
ELASTICSEARCH_ENABLED=false              # Enable Elasticsearch
RELAY_GATEWAY_ENABLED=false              # Enable relay gateway
MERCURE_JWT_SECRET=!ChangeMe!            # Mercure secret
SERVER_NAME=localhost                    # Domain name
```

## Useful SQL Queries

```sql
-- Article counts
SELECT COUNT(*) FROM article;
SELECT event_status, COUNT(*) FROM article GROUP BY event_status;

-- Recent articles
SELECT id, title, created_at FROM article ORDER BY created_at DESC LIMIT 10;

-- User / magazine counts
SELECT COUNT(*) FROM "user";
SELECT COUNT(*) FROM magazine;

-- Graph layer health
SELECT COUNT(*) FROM current_record;
SELECT COUNT(*) FROM parsed_reference;
```

## Troubleshooting

### Can't access localhost:8443

```bash
docker compose ps                        # Check services
docker compose logs php                  # Check PHP logs
docker compose restart                   # Restart everything
```

### Articles not appearing

```bash
docker compose ps worker-relay           # Check relay worker
docker compose logs worker-relay         # Check worker logs
```

### Cache issues

```bash
docker compose exec php bin/console cache:clear
docker compose exec php rm -rf var/cache/*
```

### Database issues

```bash
docker compose ps database              # Check database
docker compose exec database psql -U app -d app -c "SELECT 1"
docker compose restart database
```

## More Information

- [Getting Started](GETTING-STARTED.md)
- [Features](FEATURES.md)
- [Architecture](ARCHITECTURE.md)
- [Developer Guide](DEVELOPER-GUIDE.md)
- [FAQ](FAQ.md)
- [Setup Guide](../docs/SETUP.md)
