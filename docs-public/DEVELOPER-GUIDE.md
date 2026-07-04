# Developer Guide

Guide for developers who want to understand the codebase, contribute, or extend the platform.

## Development Setup

### Prerequisites

- Docker & Docker Compose
- Git
- Text editor / IDE (PhpStorm, VS Code recommended)

### Quick Start

```bash
# Clone the repository
git clone https://github.com/decent-newsroom/newsroom.git
cd newsroom

# Copy environment template
cp .env.dist .env

# Build and start containers
docker compose build
docker compose up -d

# Check logs
docker compose logs -f php

# Access the application
# https://localhost:8443 (accept the self-signed certificate warning)
```

### Development Environment

The development setup includes:

- **FrankenPHP** — PHP app server + Caddy web server + Mercure hub
- **PostgreSQL** — Database (accessible on localhost:5432; version via `POSTGRES_VERSION`)
- **Redis** — Cache, sessions, and message queue
- **Strfry** — Local Nostr relay (ws://localhost:7777)
- **Strfry-Essayist** — Writer-first longform relay (activate via `--profile essayist`, exposed through `essayist-gateway`)
- **Worker** — Background Messenger consumers
- **Worker-Relay** — Local relay subscription workers
- **Worker-Profiles** — Profile refresh and profile queue consumer
- **Cron** — Scheduled tasks

### Useful Commands

```bash
# Enter PHP container
docker compose exec php bash

# Run Symfony console commands
docker compose exec php bin/console <command>

# Watch logs
docker compose logs -f php
docker compose logs -f worker
docker compose logs -f worker-relay

# Restart services
docker compose restart php

# Rebuild after dependency changes
docker compose build php
docker compose up -d

# Clear caches
docker compose exec php bin/console cache:clear

# Database migrations
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console doctrine:migrations:migrate

# Asset compilation (after changing JS/CSS in assets/)
docker compose exec php bin/console asset-map:compile

# Add a JS package
docker compose exec php bin/console importmap:require <package-name>
```

## Project Structure

### Directory Layout

```
newsroom/
├── assets/                  # Frontend JavaScript, TypeScript, and styles
│   ├── controllers/         # Stimulus controllers organized by domain:
│   │   ├── analytics/       #   Chart controllers
│   │   ├── blog/            #   Subdomain check
│   │   ├── content/         #   Home tabs, author articles, reading list dropdown
│   │   ├── editor/          #   Quill, mentions, embeds, media, markdown sync
│   │   ├── media/           #   Image loaders, media library, upload, gallery, playlist
│   │   ├── nostr/           #   Signing, publishing, comments, interests, relay auth
│   │   ├── publishing/      #   Image upload, Quill editor, tabular data
│   │   ├── search/          #   Search visibility, topic filter, nostr redirect
│   │   ├── ui/              #   Article broadcast, sidebar, wizard, gallery
│   │   └── utility/         #   Login, toast, clipboard, KaTeX, signer modal
│   ├── typescript/          # nostr-utils.ts, app.ts
│   └── styles/              # CSS: 01-base/ 02-layout/ 03-components/ 04-pages/ 05-utilities/
├── bin/                     # Executable scripts
│   ├── console              # Symfony console
│   └── phpunit              # Test runner
├── config/                  # Configuration files
│   ├── packages/            # Bundle configurations
│   ├── routes/              # Route definitions
│   └── services.yaml        # Service container config
├── docker/                  # Docker support files
│   ├── cron/                # Cron scripts and crontab
│   ├── strfry/              # Main relay config
│   ├── strfry-essayist/     # Essayist relay config
│   └── essayist-gateway/    # Essayist relay gateway config
├── documentation/           # Internal technical docs (one file per feature)
│   ├── NIP/                 # Nostr Implementation Possibilities reference
│   └── NKBIP/               # Nostr Key Binding Implementation Possibilities
├── docs/                    # Setup and operations docs
├── docs-public/             # Public-facing documentation
├── migrations/              # Database migrations
├── public/                  # Public web root
├── src/                     # Application source code
│   ├── Command/             # Console commands
│   ├── Controller/          # HTTP controllers
│   │   ├── Administration/  # Admin-only routes (/admin/*)
│   │   ├── Api/             # JSON API endpoints (/api/*)
│   │   ├── Editor/          # Article editor
│   │   ├── Media/           # Media discovery + manager
│   │   ├── Newsroom/        # Magazine wizard, reading lists
│   │   ├── Reader/          # Article view, highlights, forum, home feed
│   │   ├── Search/          # Search + user search
│   │   └── Subscription/    # Active indexing, vanity names, subdomains, Updates Pro
│   ├── Credits/             # (Deprecated) Search credits system
│   ├── Doctrine/            # Custom Doctrine types/extensions
│   ├── Dto/                 # Data transfer objects
│   ├── Entity/              # Doctrine entities
│   ├── Enum/                # PHP enums (KindsEnum, RolesEnum, etc.)
│   ├── EventListener/       # Doctrine/Symfony event listeners
│   ├── EventSubscriber/     # Event subscribers
│   ├── ExpressionBundle/    # Publishable feed expressions (NIP-EX kind 30880)
│   ├── Factory/             # Factory classes (Redis, etc.)
│   ├── Form/                # Symfony form types
│   ├── Message/             # Symfony Messenger message definitions
│   ├── MessageHandler/      # Async message handlers
│   ├── Messenger/           # Messenger middleware
│   ├── Provider/            # Data providers (Elasticsearch)
│   ├── ReadModel/           # Redis view objects (RedisArticleView, etc.)
│   ├── Repository/          # Doctrine repositories
│   ├── Security/            # NostrAuthenticator (NIP-07/NIP-46)
│   ├── Service/             # Business logic
│   │   ├── Admin/           # Admin dashboard, relay admin
│   │   ├── Cache/           # RedisCacheService, RedisViewStore
│   │   ├── Graph/           # Graph layer (current_record, parsed_reference)
│   │   ├── LatestArticles/  # Bot filtering exclusion policy
│   │   ├── Media/           # Blossom/NIP-96 media providers
│   │   ├── Nostr/           # Core Nostr layer (20+ services)
│   │   ├── RSS/             # RSS feed parsing
│   │   └── Search/          # Dual search implementation
│   ├── Session/             # Resilient Redis session handler
│   ├── Twig/                # Twig components and extensions
│   │   └── Components/      # Atomic Design: Atoms, Molecules, Organisms
│   ├── UnfoldBundle/        # Magazine subdomain hosting bundle
│   └── Util/                # Converters, parsers, NostrKeyUtil
├── templates/               # Twig templates
│   └── components/          # Component templates (mirrors Twig/Components/)
├── tests/                   # Test suite
│   ├── NIPs/                # Protocol compliance (.feature specs)
│   ├── Service/             # Integration tests
│   ├── Unit/                # Unit tests
│   └── Util/                # Utility tests
├── translations/            # i18n YAML files (en, de, es, fr, sl, it)
├── compose.yaml             # Docker Compose base config
├── compose.override.yaml    # Dev overrides
├── compose.prod.yaml        # Production overrides
├── Dockerfile               # Container image definition
├── importmap.php            # AssetMapper package management
└── composer.json            # PHP dependencies
```

## Core Concepts

### Nostr Event Flow

```
1. Event Creation
   ├─ User action (publish article, create magazine, etc.)
   ├─ NostrEventBuilder creates event structure
   ├─ Sign with user's key (NIP-07 browser or NIP-46 remote)
   └─ Broadcast to relays via NostrRequestExecutor

2. Event Ingestion
   ├─ Local strfry relay receives event (via router or direct publish)
   ├─ worker-relay subscription picks up event
   ├─ Validates and routes to appropriate projector:
   │   ├─ ArticleEventProjector (kind 30023/30024)
   │   ├─ MediaEventProjector (kinds 20/21/22)
   │   ├─ GenericEventProjector (all other kinds)
   │   └─ CommentEventProjector (kind 1111)
   └─ EventIngestionListener updates graph layer

3. Event Display
   ├─ Controller queries Redis cache first, DB fallback
   ├─ GraphLookupService resolves magazine structures
   ├─ Renders Twig template with data
   └─ Returns HTTP response
```

### Async Processing

Five Messenger transport lanes (Redis streams):

| Transport | Consumer | Handles |
|-----------|----------|---------|
| `async` | `worker` | Article fetching, comment fetching, media events, relay queries |
| `async_low_priority` | `worker` | Gateway persistence, login warmup, relay list updates |
| `async_expressions` | `worker` | User-facing expression and spell evaluation |
| `async_relay_feeds` | `worker` | Time-bounded relay feed WebSocket subscriptions |
| `async_profiles` | `worker-profiles` | Profile metadata batch fetches, cache revalidation |

**Defining a message:**
```php
// src/Message/FetchAuthorArticlesMessage.php
class FetchAuthorArticlesMessage
{
    public function __construct(
        public readonly string $pubkey,
    ) {}
}
```

**Creating a handler:**
```php
// src/MessageHandler/FetchAuthorArticlesHandler.php
#[AsMessageHandler]
class FetchAuthorArticlesHandler
{
    public function __invoke(FetchAuthorArticlesMessage $message): void
    {
        // Process...
    }
}
```

### Relay Infrastructure

Two-tier relay model:

- **Tier 1 (base):** Local strfry relay for anonymous/read-only traffic, subscription workers
- **Tier 2 (user):** NIP-65 relay lists (kind 10002) activated on login for personalized reads/publishes

Key services:
- `RelayRegistry` — Single source for all relay URLs, configured in `services.yaml`
- `RelayHealthStore` — Redis-backed health tracking per relay
- `UserRelayListService` — Stale-while-revalidate relay resolution
- `FollowsRelayPoolService` — Consolidated pool from followed authors' write relays

## Common Development Tasks

### Adding a Stimulus Controller

```javascript
// assets/controllers/my-domain/my_feature_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['output'];
    static values = { message: String };

    connect() {
        console.log('Connected');
    }

    greet() {
        this.outputTarget.textContent = this.messageValue;
    }
}
```

Use in template:
```twig
<div data-controller="my-domain--my-feature"
     data-my-domain--my-feature-message-value="Hello">
    <button data-action="click->my-domain--my-feature#greet">Click</button>
    <div data-my-domain--my-feature-target="output"></div>
</div>
```

### Adding a New Entity

```bash
# 1. Create entity class in src/Entity/
# 2. Generate migration
docker compose exec php bin/console doctrine:migrations:diff
# 3. Review migration in migrations/
# 4. Run migration
docker compose exec php bin/console doctrine:migrations:migrate
```

### Working with the Graph Layer

The graph layer tables (`current_record`, `parsed_reference`) are raw-SQL tables excluded from Doctrine schema diffs via `doctrine.dbal.schema_filter`. They are maintained by `EventIngestionListener` on every event persist.

```bash
# Backfill current records
docker compose exec php bin/console dn:graph:backfill-current-records

# Backfill references
docker compose exec php bin/console dn:graph:backfill-references

# Audit consistency
docker compose exec php bin/console dn:graph:audit --fix

# Rebuild single coordinate
docker compose exec php bin/console dn:graph:rebuild-record <coordinate>
```

## Testing

### Running Tests

```bash
# Run all tests
docker compose exec php bin/phpunit

# Show configured PHPUnit suites
docker compose exec php bin/phpunit --list-suites

# Run one suite
docker compose exec php bin/phpunit --testsuite Unit

# Run specific test file
docker compose exec php bin/phpunit tests/Unit/Util/RelayUrlNormalizerTest.php
```

### Test Structure

- `tests/Unit/` — Pure unit tests (`--testsuite Unit`)
- `tests/Service/` — Integration tests (`--testsuite Service`)
- `tests/Security/` — Auth and security tests (`--testsuite Security`)
- `tests/UnfoldBundle/` — Hosted magazine tests (`--testsuite UnfoldBundle`)
- `tests/Util/` — Utility-focused tests (`--testsuite Util`)
- `tests/NIPs/` — Gherkin `.feature` protocol specs (Behat/docs, not PHPUnit)
- `tests/NostrTestHelpers.php` — Shared Nostr test helpers
- `tests/README.md` — Suite overview and common commands

## Contributing

### Workflow

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Make changes following code standards
4. Test: `docker compose exec php bin/phpunit`
5. Commit with clear messages
6. Push and create a Pull Request

### Code Standards

- **PHP:** PSR-12, type hints, return types
- **Symfony:** Attributes for routing, service autowiring, best practices
- **JavaScript:** ES6+, Stimulus conventions, domain-organized controllers
- **Twig:** Semantic HTML, Atomic Design component structure
- **CSS:** No rounded edges, no shading, CSS variables in `theme.css`
- **Documentation:** One file per feature in `documentation/`, update CHANGELOG.md

### Adding Features

1. Add JS/CSS to `assets/` (not inline in templates)
2. Document in `documentation/` (one markdown file per feature)
3. Add to CHANGELOG.md under the current development version
4. Add translation keys to all locale files

## Environment Variables

Key variables (see `.env.dist` for complete list):

```bash
APP_ENV=dev                              # dev or prod
APP_SECRET=change_me                     # Secret key
DATABASE_URL=postgresql://...            # PostgreSQL connection
REDIS_HOST=redis                         # Redis host
REDIS_PASSWORD=r_password                # Redis password
NOSTR_DEFAULT_RELAY=ws://strfry:7777     # Local relay
ELASTICSEARCH_ENABLED=false              # Enable Elasticsearch
RELAY_GATEWAY_ENABLED=false              # Enable relay gateway
MERCURE_JWT_SECRET=!ChangeMe!            # Mercure hub secret
SERVER_NAME=localhost                    # Domain name
```

## Debugging

### Profiler

In dev mode, Symfony Profiler is available at `/_profiler`. Click the debug toolbar at the bottom of any page.

### Xdebug

See [docs/xdebug.md](../docs/xdebug.md) for step debugging setup.

### Logs

```bash
# Application logs
docker compose logs -f php

# Worker logs
docker compose logs -f worker
docker compose logs -f worker-relay
docker compose logs -f worker-profiles

# Symfony logs
docker compose exec php tail -f var/log/dev.log
```

### Database

```bash
# Interactive psql
docker compose exec database psql -U app -d app

# Run SQL via console
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) FROM article"
```

## Additional Resources

- [Symfony Documentation](https://symfony.com/doc/current/index.html)
- [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html)
- [Stimulus Handbook](https://stimulus.hotwired.dev/handbook/introduction)
- [Nostr Protocol](https://github.com/nostr-protocol/nostr)
- [NIPs Repository](https://github.com/nostr-protocol/nips)
