# Architecture Overview

Decent Newsroom is a decentralized collaborative publishing platform built on the Nostr protocol. This document explains how the system is architected and how its components work together.

## Core Stack

- **Framework**: Symfony 7.4 (PHP 8.3+)
- **Web Server**: FrankenPHP (PHP application server + Caddy web server)
- **Database**: PostgreSQL 16+ (version set via `POSTGRES_VERSION`; the current compose file defaults the image to 16 while `DATABASE_URL` declares server version 17)
- **Cache/Queue**: Redis (sessions, cache, Messenger transport, view store)
- **Search**: Elasticsearch (optional, feature-flagged via `ELASTICSEARCH_ENABLED`)
- **Real-time**: Mercure (SSE push to browser, built into FrankenPHP/Caddy)
- **Frontend**: Stimulus + AssetMapper (no npm/webpack), TypeScript via `sensiolabs/typescript-bundle`
- **Nostr Relay**: Strfry (local event caching and relay) + Strfry-Essayist (writer-first relay, `--profile essayist`) + optional Relay Gateway (`--profile gateway`)

## Service Architecture

The application runs as multiple Docker containers:

```
┌─────────────────────────────────────────────────────────────┐
│                         User Browser                         │
└─────────────────┬───────────────────────────────────────────┘
                  │ HTTPS (443)
                  ↓
┌─────────────────────────────────────────────────────────────┐
│  FrankenPHP (php)                                           │
│  ├─ Caddy Web Server (TLS, reverse proxy)                   │
│  ├─ PHP application (Symfony)                               │
│  └─ Mercure Hub (Server-Sent Events)                        │
└─────┬──────────────────┬──────────────────┬────────────────┘
      │                  │                  │
      ↓                  ↓                  ↓
┌──────────┐  ┌──────────────────┐  ┌────────────────────────────────┐
│PostgreSQL│  │ Redis            │  │ Strfry (ws://7777)             │
│ 16+      │  │ (queue + cache   │  │ Strfry-Essayist (internal)     │
│          │  │  + sessions)     │  │ Relay Gateway (profile)        │
└──────────┘  └──────────────────┘  └────────────────────────────────┘
```

### Docker Services (`compose.yaml`)

| Service | Command | Role |
|---------|---------|------|
| `php` | — | FrankenPHP (app server + Caddy + Mercure) |
| `database` | — | PostgreSQL (version set via `POSTGRES_VERSION`) |
| `redis` | — | Cache, sessions, Messenger transport, view store (in `compose.override.yaml` for dev) |
| `strfry` | relay + router | Local Nostr relay (article/event cache) |
| `strfry-essayist` | relay | Writer-first longform relay (activate via `--profile essayist`) |
| `essayist-gateway` | relay proxy | Public ingress for the Essayist relay (activate via `--profile essayist`) |
| `cron` | crontab | Scheduled background tasks |
| `worker` | `app:run-workers` | Messenger consumers: async, async_low_priority, async_expressions, async_relay_feeds |
| `worker-relay` | `app:run-relay-workers` | Local relay subscriptions: articles, media, magazines, user context |
| `worker-profiles` | `app:run-profile-workers` | Profile refresh daemon + async_profiles consumer |
| `relay-gateway` | `app:relay-gateway` | Persistent relay connection pool with NIP-42 AUTH (activate via `--profile gateway`) |

## Data Flow

### Article Publishing

```
User writes article → Editor UI → Save as draft (DB)
                                        ↓
                     Publish → Sign with Nostr key (NIP-07 or NIP-46)
                                        ↓
                      Create kind 30023 event → Broadcast to relays
                                        ↓
              Local Strfry relay receives event via router
                                        ↓
           worker-relay subscription → ArticleEventProjector
                                        ↓
              PostgreSQL + Graph layer + Elasticsearch (if enabled)
                                        ↓
                          Article visible in Reader
```

### Magazine Index

```
User creates magazine → Wizard UI → Create kind 30040 event (NKBIP-01)
                                        ↓
               Sign & broadcast → Local Strfry + external relays
                                        ↓
           worker-relay subscription → Graph layer ingestion
                                        ↓
              current_record + parsed_reference tables updated
                                        ↓
                GraphLookupService resolves structure
                                        ↓
                Magazine visible on newsstand
```

### Authentication

```
User → Login page → Choose method
         │
         ├─ NIP-07 Browser Extension (Alby, nos2x, etc.)
         │    └─ Direct key access in browser
         │
         └─ NIP-46 Remote Signer (Nsec Bunker)
              └─ Remote signing via relay communication
                                        ↓
                    Verify Nostr signature → Create session
                                        ↓
                     Fetch user profile (kind 0) + relay list (kind 10002)
                                        ↓
             Async: warm follows relay pool, sync user events
                                        ↓
                          User authenticated
```

## Core Components

### Domain Entities (`src/Entity/`)

- **Article** — Long-form content (NIP-23, kinds 30023/30024)
- **Event** — Generic Nostr event storage (raw JSON preserved)
- **User** — Author profiles and identity (kind 0 metadata)
- **Magazine** — Projected magazine indices (kind 30040, NKBIP-01)
- **Highlight** — Article annotations (kind 9802, NIP-84)
- **UserRelayList** — NIP-65 relay lists (kind 10002)
- **VanityName** — Custom NIP-05 identifiers
- **ActiveIndexingSubscription** — Priority indexing service
- **PublicationSubdomainSubscription** — Unfold hosted magazines
- **UnfoldSite** — Magazine subdomain configuration
- **FollowPackSource** — Follow pack assignments for home feed tabs
- **HiddenCoordinate** — Admin-hidden magazine coordinates
- **MediaAssetCache** / **MediaPostCache** — Cached media events
- **UserUpload** — User-uploaded files
- **Visit** — Visitor analytics

### Nostr Integration (`src/Service/Nostr/`)

- **NostrClient** — High-level API for Nostr operations (fetch, publish)
- **NostrRelayPool** — Manages WebSocket relay connections and subscriptions
- **NostrRequestExecutor** — Low-level relay request execution with timeouts
- **NostrEventBuilder** — Creates properly formatted Nostr events
- **NostrEventParser** — Parses event tags, coordinates, metadata
- **NostrSigner** — Server-side event signing
- **RelayRegistry** — Single source for all relay URLs (profile, content, project, signer)
- **RelayHealthStore** — Redis-backed per-relay health tracking (success/failure, latency)
- **UserRelayListService** — Stale-while-revalidate relay list resolution (Redis → DB → network → fallback)
- **RelayGatewayClient** — Redis Streams interface to the gateway process
- **RelaySetFactory** — Builds purpose-specific relay sets
- **FollowsRelayPoolService** — Consolidated relay pool from followed authors' write relays
- **ArticleFetchService** — Article-specific fetch with coordinate resolution
- **MediaEventService** — Media event queries
- **SocialEventService** — Article social context (reactions, comments, zaps, highlights)
- **UserProfileService** — Profile metadata resolution and caching

### Graph Layer (`src/Service/Graph/`)

The graph layer maintains a materialized view of Nostr's replaceable event structure in PostgreSQL:

- **`current_record`** table — Tracks the newest version of each replaceable event coordinate
- **`parsed_reference`** table — Normalized `a`-tag references between events
- **CurrentVersionResolver** — Atomic upsert with tie-break for newest-wins resolution
- **ReferenceParserService** — Extracts and classifies references from event tags
- **RecordIdentityService** — Canonical coordinate strings and UID generation
- **GraphLookupService** — Recursive CTE-based tree traversal for magazine/category/article resolution
- **GraphMagazineListService** — Graph-backed magazine listing (replaces deprecated `MagazineProjector`)
- **EventIngestionListener** — Keeps graph tables current on every event persist

Used by: Unfold subdomain hosting, newsstand, bookshelf, magazine resolution.

### Background Workers

**worker** (`app:run-workers`):
- Messenger consumers for `async`, `async_low_priority`, `async_expressions`, and `async_relay_feeds` transports
- Handles: article fetching, comment fetching, media events, expression evaluation, relay-feed subscriptions, gateway persistence, login warmup, relay list updates

**worker-relay** (`app:run-relay-workers`):
- Persistent WebSocket subscriptions to local strfry relay
- `SubscribeLocalRelayCommand` — kind 30023 articles
- `SubscribeLocalMediaCommand` — kinds 20, 21, 22 media
- `SubscribeLocalMagazinesCommand` — kind 30040 magazines
- `SubscribeLocalUserContextCommand` — user identity/social events (kinds 0, 3, 10000–10063, 30003–30015, etc.)

**worker-profiles** (`app:run-profile-workers`):
- `ProfileRefreshWorkerCommand` — Finds stale profiles, dispatches batch updates
- Messenger consumer for `async_profiles` transport

**relay-gateway** (`app:relay-gateway`):
- Persistent WebSocket connection pool to external relays
- On-demand connections with idle TTL
- NIP-42 AUTH handling via Mercure roundtrip signing
- Events forwarded to local strfry for storage

### Search System

Dual-mode search with factory-based selection:

- **`ArticleSearchInterface`** / **`UserSearchInterface`** — Common interfaces
- **`DatabaseArticleSearch`** / **`DatabaseUserSearch`** — PostgreSQL full-text search (default)
- **`ElasticsearchArticleSearch`** / **`ElasticsearchUserSearch`** — Elasticsearch (when `ELASTICSEARCH_ENABLED=true`)
- **`Nip05AwareUserSearch`** — Wraps user search with NIP-05 identifier resolution
- Advanced search filters: date range, author, tags, content type, sort order

### Redis Views (Read Models)

- `src/ReadModel/RedisView/` — Typed read-only objects (RedisArticleView, RedisProfileView)
- `RedisViewStore` writes them; controllers read from cache first, DB fallback
- Rebuilt by cron commands (`app:cache-latest-articles`, `app:cache-latest-highlights`)

### UnfoldBundle (`src/UnfoldBundle/`)

Magazine subdomain hosting:
- Renders magazines at custom subdomains
- Theme support
- Resolves content via GraphLookupService (local DB, no relay round-trips)
- Cache warming via cron

### Frontend Architecture

Zero-build-step frontend:

- **Symfony AssetMapper** — Maps assets without webpack/npm (`importmap.php`)
- **Stimulus Controllers** — Organized by domain in `assets/controllers/` (admin, analytics, blog, content, editor, media, nostr, publishing, search, ui, utility)
- **TypeScript** — Compiled via `sensiolabs/typescript-bundle` (`assets/typescript/`)
- **Turbo** — Fast page navigation
- **Twig Live Components** — Server-rendered interactive UI (Atomic Design: Atoms → Molecules → Organisms)
- **Symfony UX Icons** — Icon bundle

### Twig Components (`src/Twig/Components/`)

Atomic Design pattern:
- **Atoms/** — Alert, Button, Pagination, Nip05Badge, etc.
- **Molecules/** — Card, ZapButton, UserFromNpub, NostrPreview, etc.
- **Organisms/** — ArticleFromCoordinate, Comments, CardList, FeaturedList, etc.
- Top-level components: Header, Footer, UserMenu, SearchComponent, ReadingListDropdown, FollowPackDropdown

## Nostr Event Kinds

Tracked in `src/Enum/KindsEnum.php`:

| Kind | Name | Purpose |
|------|------|---------|
| 0 | Metadata | User profiles |
| 1 | Text Note | NIP-01 (tracked, not implemented) |
| 3 | Follows | Contact lists |
| 5 | Deletion Request | NIP-09 event deletion |
| 7 | Reaction | Likes/reactions |
| 16 | Generic Repost | NIP-18 repost of any kind |
| 20 | Image | NIP-68 image events |
| 21 | Video | NIP-71 video events |
| 22 | Short Video | NIP-71 short-form video |
| 777 | Spell | NIP-A7 portable query filters |
| 1063 | File Metadata | NIP-94 file metadata |
| 1111 | Comments | Article comments |
| 1450 | Tabular Data | CSV data (NIP-XX) |
| 1984 | Report | NIP-56 content reporting |
| 1985 | Label | NIP-32 labeling / content classification |
| 9734 | Zap Request | NIP-57 zap request |
| 9735 | Zap Receipt | NIP-57 zaps |
| 9802 | Highlights | NIP-84 annotations |
| 10000 | Mute List | NIP-51 user mutes |
| 10001 | Pin List | NIP-51 pinned notes |
| 10002 | Relay List | NIP-65 relay metadata |
| 10003 | Bookmarks | NIP-51 bookmarks |
| 10015 | Interests | NIP-51 interest tags |
| 10020 | Media Follows | NIP-68 multimedia follow list |
| 10063 | Blossom Server List | NIP-B7 user Blossom server list |
| 10166 | Relay Monitor Announcement | NIP-66 relay monitor self-announcement |
| 27235 | HTTP Auth | NIP-98 HTTP authentication |
| 30003 | Bookmark Sets | NIP-51 categorized bookmarks |
| 30004 | Curation Set | NIP-51 article curation |
| 30005 | Video Curation | NIP-51 video playlists |
| 30006 | Picture Curation | NIP-51 picture boards |
| 30015 | Interest Sets | NIP-51 interest sets (hashtag groups) |
| 30023 | Long-form | NIP-23 articles |
| 30024 | Long-form Draft | NIP-23 drafts |
| 30040 | Publication Index | NKBIP-01 magazine indices |
| 30041 | Publication Content | NKBIP-01 magazine content/AsciiDoc |
| 30078 | App Data | NIP-78 arbitrary custom app data |
| 30166 | Relay Discovery | NIP-66 relay liveness/monitoring |
| 30880 | Feed Expression | NIP-EX publishable feed expressions |
| 34139 | Playlist | Music playlists |
| 34235 | Addressable Video | NIP-71 addressable video |
| 34236 | Addressable Short Video | NIP-71 addressable short video |
| 39089 | Follow Pack | Follow packs / starter packs |

## Cron Jobs (`docker/cron/crontab`)

| Schedule | Job |
|----------|-----|
| `*/5 min` | Subscription payment checks (active indexing, vanity names, Essayist memberships) |
| `*/10 min` | Post-process articles (QA, indexing) |
| `*/15 min` | Cache latest articles to Redis |
| `*/30 min` | Fetch highlights from relays |
| `*/30 min` | Cache latest highlights |
| `*/30 min` | Project magazine indices |
| `*/30 min` | Warm Unfold site caches |
| `*/59 min` | Updates Pro subscription expiry check |
| `6h` | Cache media discovery events |
| `6h` | Refresh NIP-11 relay information documents |
| `daily 1AM` | Release expired vanity name subscriptions |
| `daily 2AM` | Backfill historical articles |
| `daily 3AM` | Graph layer consistency audit (`dn:graph:audit --fix`) |
| `monthly` | Expire Essayist memberships |
| `*/10 min` | Cache XML sitemap to Redis |

## Security Model

### Authentication

- **Nostr-based auth** — Users sign challenges with private keys
- **NIP-07** — Browser extension integration (Alby, nos2x, etc.)
- **NIP-46** — Remote signer support (Nsec Bunker)
- **Session management** — Resilient Redis-backed sessions with 7-day inactivity TTL

### Authorization

Role-based access control:
- `ROLE_USER` — Basic authenticated user
- `ROLE_WRITER` — Auto-granted on article publish
- `ROLE_EDITOR` — Auto-granted on magazine/reading list publish
- `ROLE_ADMIN` — Full system access
- `ROLE_ACTIVE_INDEXING` — Priority indexing service
- `ROLE_UPDATES_PRO` — Updates Pro subscribers
- `ROLE_FEATURED_WRITER` — Highlighted in discovery
- `ROLE_RSS` — RSS import administration access
- `ROLE_MUTED` — Admin-muted (excluded from feeds)
- `ROLE_ESSAYIST_CANDIDATE` — Essayist relay candidate
- `ROLE_ESSAYIST_MEMBER` — Essayist relay member
- `ROLE_ESSAYIST_EARLY_BIRD` — Essayist early-bird member

## Deployment

### Development

```bash
docker compose up -d
```

Uses `compose.yaml` + `compose.override.yaml`: self-signed certificates, debug mode, local volume mounts.

### Production

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d
```

Uses `compose.yaml` + `compose.prod.yaml`: automatic Let's Encrypt TLS, optimized PHP settings, no debug output.

### With Relay Gateway

```bash
docker compose --profile gateway up -d
```

Requires `RELAY_GATEWAY_ENABLED=true` on the php service.

## i18n

Translation files in `translations/messages.{locale}.yaml`:
- English (en), German (de), Spanish (es), French (fr), Slovenian (sl), Italian (it)
- All user-facing text uses `{{ 'key'|trans }}` in Twig

## Related Documentation

- [Setup Guide](../docs/SETUP.md) — Installation and configuration
- [Production Deployment](../docs/production.md) — Production setup
- [Feature Documentation](../documentation/INDEX.md) — Individual feature docs
- [Developer Guide](DEVELOPER-GUIDE.md) — Contributing and extending
