Motto: "What you can live with, what you can't live without."

# Instructions

This is a Symfony application using AssetMapper to manage assets. The `assets` directory contains all the static files such as CSS, JavaScript, and images. The `public` directory is the web root where the compiled assets are served from.
And using Twig Live Components to create dynamic, interactive components in the frontend. Twig Live Components allow you to build reusable components that can update themselves without requiring a full page reload, enhancing the user experience. 

- When creating JS or CSS, put it in the `assets` folder, not inline in the templates.
- When documenting the features, use the `documentation` folder and create a markdown file for each feature. This will help keep the documentation organized and easily accessible for future reference. Avoid creating multiple files.
- Run any commands inside the docker container, to ensure that the environment is consistent and to avoid any issues with dependencies or configurations. This will help maintain a smooth development workflow and prevent any potential conflicts with the local environment.
- This is a Nostr protocol client. Particular definitions and docs are in the `documentation/NIP` and `documentation/NKBIP` folders. NIP stands for Nostr Implementation Possibilities, and NKBIP stands for Nostr Key Binding Implementation Possibilities. These folders contain detailed documentation on the specific implementations and features related to the Nostr protocol, which is essential for understanding how the client interacts with the protocol and how to implement various functionalities effectively.
- Add features and bugfixes to the Changelog file. One item per feature or fix. Assume that the topmost version is currently in development and that the one before it is currently live.

## Automatic approval denials

If automatic approval review explicitly denies an action, stop that action immediately. Do not retry it through another command, tool, smaller payload, or indirect route.
Tell the user which exact action was denied and the reviewer’s stated reason, then ask for their approval before attempting that action again. If a safer alternative is useful, describe it and ask for approval for that specific alternative too.
Continue only unrelated work that is already authorized while waiting. Never treat silence as approval. If a managed policy cannot be overridden by the user, explain that limit instead of retrying.

## Project Architecture

### Tech Stack
- **PHP 8.3+** / **Symfony 7.4** with FrankenPHP runtime
- **PostgreSQL 17** (primary database), **Redis** (cache, sessions, Messenger transport, view store)
- **Doctrine ORM** with migrations in `migrations/`
- **strfry** Nostr relay (local cache for articles/events, connected via WebSocket)
- **Mercure** (SSE push to browser, bundled in FrankenPHP/Caddy)
- **Elasticsearch** (optional, feature-flagged via `ELASTICSEARCH_ENABLED`)
- **AssetMapper + Stimulus** for JS (no npm/webpack) — `importmap.php` manages packages
- **TypeScript** compiled via `sensiolabs/typescript-bundle` (`assets/typescript/`)
- **Twig Live Components** for server-rendered interactive UI
- **Symfony Messenger** for async jobs (Redis transport, two priority queues)
- **Symfony Workflow** for article publishing and reading list state machines (`config/packages/workflow.yaml`)

### Docker Services (`compose.yaml`)
| Service | Role |
|---------|------|
| `php` | FrankenPHP (app server + Caddy + Mercure) |
| `database` | PostgreSQL |
| `redis` | Cache, sessions, Messenger transport, view store |
| `strfry` | Local Nostr relay (article/event cache) + router |
| `cron` | Scheduled background tasks (`docker/cron/crontab`) |
| `worker` | Messenger consumers via `app:run-workers`: async (content fetches) + async_low_priority (gateway persistence, login warmup) |
| `worker-relay` | Local relay subscription workers via `app:run-relay-workers`: article, media, magazine hydration from strfry |
| `worker-profiles` | Profile workers via `app:run-profile-workers`: profile refresh daemon + async_profiles consumer |
| `relay-gateway` | Persistent relay connection pool with NIP-42 AUTH (activated via `--profile gateway`) |

### Directory Layout
```
src/
  Command/          # CLI commands (app:run-workers, app:cache-latest-articles, etc.)
  Controller/
    Administration/ # Admin-only routes (/admin/*)
    Api/            # JSON API endpoints (/api/*)
    Editor/         # Article editor
    Media/          # Media discovery + manager
    Newsroom/       # Magazine wizard, reading lists
    Reader/         # Article view, highlights, forum, home feed tabs
    Search/         # Search + user search
    Subscription/   # Active indexing, vanity names, publication subdomains
  Dto/              # Data transfer objects (UserMetadata, MediaAttachment, etc.)
  Entity/           # Doctrine entities (Article, Event, User, Magazine, etc.)
  Enum/             # PHP enums (KindsEnum for Nostr event kinds, RolesEnum, etc.)
  Message/          # Symfony Messenger messages
  MessageHandler/   # Async message handlers
  ReadModel/        # Redis view objects (RedisArticleView, RedisProfileView, etc.)
  Repository/       # Doctrine repositories
  Security/         # NostrAuthenticator (NIP-07/NIP-46 login)
  Service/
    Nostr/          # Core Nostr layer: NostrClient, RelayRegistry, RelayGatewayClient,
                    #   UserRelayListService, RelayHealthStore, NostrEventBuilder/Parser
    Cache/          # RedisCacheService, RedisViewStore
    Search/         # ArticleSearchInterface (Elasticsearch or Database impl, factory-selected)
    Media/          # Blossom/NIP-96 media providers, publisher
    Admin/          # AdminDashboardService, RelayAdminService
    LatestArticles/ # Bot filtering exclusion policy
  Twig/Components/  # Twig Live Components (Atomic Design: Atoms → Molecules → Organisms)
  UnfoldBundle/     # Hosted magazine subdomain rendering (self-contained bundle)
  Util/             # CommonMark converter, AsciiDoc parser, ImetaParser, NostrKeyUtil

assets/
  controllers/      # Stimulus controllers organized by domain:
    analytics/      #   Chart controllers
    content/        #   Home tabs, author articles, reading list dropdown
    editor/         #   Quill, mentions, embeds, media, markdown sync
    media/          #   Image/media loaders, media library, upload
    nostr/          #   Signing, publishing, comments, interests, relay auth
    publishing/     #   Image upload, Quill editor, tabular data
    search/         #   Search visibility, topic filter, nostr redirect
    ui/             #   Article broadcast, back-to-top, sidebar, wizard, gallery
    utility/        #   Login, toast, clipboard, KaTeX, signer modal
  typescript/       # nostr-utils.ts (NIP-19 encode/decode), app.ts
  styles/           # CSS organized: 01-base/ 02-layout/ 03-components/ 04-pages/ 05-utilities/

templates/
  components/       # Twig component templates matching src/Twig/Components/ hierarchy
  home/tabs/        # Turbo Frame partials for home feed tabs
  admin/            # Admin dashboard, relay dashboard, follow packs, etc.
  editor/           # Article editor views
  macros/           # Reusable Twig macros

documentation/      # Feature docs (one file per feature), organized by area subfolders
  NIP/              # Nostr Implementation Possibilities reference
  NKBIP/            # Nostr Key Binding Implementation Possibilities

.agents/skills/     # Agent skills (open Agent Skills format): <name>/SKILL.md, auto-discovered
.github/agents/     # Custom agent profiles (*.agent.md)
.github/extensions/ # Copilot CLI extension entrypoints (not application assets)
```

### Key Patterns

**Relay infrastructure (two-tier):**
- Tier 1 (base): Local strfry relay for anonymous/read-only traffic, subscription workers
- Tier 2 (user): NIP-65 relay lists (kind 10002) activated on login for personalized reads/publishes
- `RelayRegistry` (`src/Service/Nostr/RelayRegistry.php`) — single source for all relay URLs, configured in `services.yaml` parameters
- `RelayHealthStore` — Redis-backed per-relay health tracking (success/failure, latency)
- `UserRelayListService` — stale-while-revalidate relay list resolution (Redis → DB → network → fallback)
- `RelayGatewayCommand` — optional persistent WebSocket connection pool with NIP-42 AUTH, runs as a separate Docker service (`relay-gateway`), activated via `--profile gateway`

**Async processing:**
- Symfony Messenger with Redis transport, messages in `src/Message/`, handlers in `src/MessageHandler/`
- Three transport lanes: `async` (content fetches), `async_low_priority` (gateway persistence, login warmup), `async_profiles` (profile updates)
- Pattern: dispatch `FetchAuthorArticlesMessage` → `FetchAuthorArticlesHandler` processes async
- `app:run-workers` runs Messenger consumers (async + async_low_priority)
- `app:run-relay-workers` runs local relay subscriptions (articles, media, magazines)
- `app:run-profile-workers` runs profile refresh daemon + async_profiles consumer

**Twig Components (Atomic Design):**
- `Atoms/` — small UI elements (Alert, Button, Pagination, Nip05Badge)
- `Molecules/` — composed elements (Card, ZapButton, UserFromNpub, NostrPreview)
- `Organisms/` — full sections (ArticleFromCoordinate, Comments, CardList, FeaturedList)
- Templates in `templates/components/` mirror the PHP class hierarchy

**Stimulus controllers:**
- Named `{domain}_{name}_controller.js`, organized by domain folder under `assets/controllers/`
- Connected to HTML via `data-controller="domain--name"` (e.g., `content--home-tabs`)
- Use Stimulus values and targets for state; fetch Turbo Frame partials for dynamic content

**Redis views (read models):**
- `src/ReadModel/RedisView/` — typed read-only objects (RedisArticleView, RedisProfileView) for fast page rendering
- `RedisViewStore` writes them; controllers read from cache first, DB fallback
- Rebuilt by cron commands (`app:cache-latest-articles`, `app:cache-latest-highlights`)

**Search (dual implementation):**
- `ArticleSearchInterface` / `UserSearchInterface` with factory-based selection
- Elasticsearch impl used when `ELASTICSEARCH_ENABLED=true`, otherwise `DatabaseArticleSearch`/`DatabaseUserSearch`
- NIP-05 aware user search wraps the base implementation

**Event kinds tracked (`src/Enum/KindsEnum.php`):**
- Articles: 30023 (longform), 30024 (draft)
- Publications: 30040 (index), 30041 (content/AsciiDoc)
- Media: 20 (images), 21 (video), 22 (short video)
- Chat: 40/41 (NIP-28 channel create/metadata), 42 (NIP-28 channel message), 43/44 (NIP-28 hide/mute)
- Social: 1111 (comments), 9802 (highlights), 7 (reactions), 9735 (zaps)
- Lists: 10015 (interests), 10003 (bookmarks), 30003/30004/30005/30006 (curation sets)
- Identity: 0 (metadata), 3 (follows), 10002 (relay list), 39089 (follow pack)

**i18n:**
- Translation files in `translations/messages.{locale}.yaml` for every enabled locale (en, de, es, fr, it, sl)
- All user-facing text uses `{{ 'key'|trans }}` in Twig


**Styles:**
- No shading.
- No rounded edges.

## Development Workflow

### Running Commands
All commands must run inside the Docker container:
```bash
# General pattern
docker compose exec php bin/console <command>

# Database migrations
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console doctrine:migrations:migrate

# Compile assets after changing JS/CSS in assets/
docker compose exec php bin/console asset-map:compile

# Add a JS package
docker compose exec php bin/console importmap:require <package-name>

# Make a user admin
docker compose exec php bin/console user:elevate <npub> ROLE_ADMIN

# Run tests
docker compose exec php bin/phpunit

# Rector — automated PHP modernisation (always dry-run first)
docker compose exec php vendor/bin/rector process --dry-run
docker compose exec php vendor/bin/rector process

# PHPStan static analysis
docker compose exec php vendor/bin/phpstan analyse
```

### Testing
- PHPUnit 9 via `bin/phpunit`, config in `phpunit.xml.dist`
- Tests in `tests/`: `Unit/` (pure unit), `Service/` (integration), `NIPs/` (Gherkin-style `.feature` specs for protocol compliance)
- Nostr test helpers in `tests/NostrTestHelpers.php`

### Cron Jobs (`docker/cron/crontab`)
| Schedule | Job |
|----------|-----|
| `*/5 min` | Post-process articles (QA, indexing) |
| `*/15 min` | Cache latest articles to Redis |
| `*/15 min` | Fetch highlights from relays |
| `*/30 min` | Cache latest highlights |
| `*/10 min` | Project magazine indices |
| `*/30 min` | Warm Unfold site caches |
| `6h` | Cache media discovery events |
| `daily 2AM` | Backfill historical articles |

### Local Agent Files
Controller-level `AGENT.md` files exist in:
- `src/Controller/Reader/AGENT.md` — reading experience context
- `src/Controller/Media/AGENT.md` — media discovery architecture
- `src/Controller/Administration/AGENT.md` — admin dashboard overview
- `src/Controller/Newsroom/AGENT.md` — magazine/reading list publishing flows

### Skills
Project skills live in `.agents/skills/<name>/SKILL.md` (open Agent Skills format, discovered by Copilot and other agents). Each has YAML frontmatter with `name` and a trigger-oriented `description`. Before implementing a pattern below, load the matching skill (e.g. via the skill tool, or read its `SKILL.md`). This table is the human index; keep it in sync.

| Skill | Use when |
|---|---|
| `add-nostr-event-kind` | A kind must be fetched, persisted, projected, or specially handled (generic display needs no registration) |
| `create-twig-component` | Adding a reusable Twig Component or, only when needed, a Live Component |
| `create-stimulus-controller` | Adding client-side JS behaviour |
| `create-async-message-handler` | Deferring work via Symfony Messenger |
| `add-doctrine-entity` | Adding a new PostgreSQL table |
| `add-redis-read-model` | Fast page rendering via pre-computed Redis views |
| `add-ingestion-gate` | Silently dropping events at ingestion (bans, tombstones) |
| `audit-ingestion-path` | Content is missing/duplicated/delayed, or measuring a fetch path's unique contribution |
| `augment-relay-selection` | Per-user relay augmentation without polluting the global registry |
| `add-console-command` | Adding a `bin/console` command (cron, admin utility, worker loop) |
| `use-content-search-api` | Searching or recommending content via `ContentSearchService` |
| `write-nip-feature-spec` | Writing a Gherkin `.feature` spec for NIP compliance |
| `add-translations` | Adding i18n strings across all enabled locale files |
| `add-feature-documentation` | Documenting a new feature in `documentation/` |
| `retire-feature` | Removing a feature, product, worker, or cron job safely |
| `run-rector` | Running and configuring Rector for automated PHP modernisation |
| `event-workspace-contract` | Changing event identity, reference resolution, previews, or collection expansion |
| `add-event-viewer` | Adding a selectable event viewer or child/member display mode |
| `migrate-event-surface` | Moving a page, embed, or inventory onto shared event previews |
| `typesafe-ai` | (installed, third-party) Typed AI judgments; see `documentation/Processes/jev-decision-record.md` |

### Custom agents
Profiles in `.github/agents/*.agent.md` define focused roles:
- `event-workspace-implementer` — implements one bounded slice of the event workspace plan.
- `event-workspace-reviewer` — read-only review of event workspace changes.

Prefer skills for repeatable procedures; add an agent profile only for a distinct role with its own permissions or review stance.

### Event workspace workflows
The optional Copilot CLI extension in `.github/extensions/event-workspace/` registers one-target migration and viewer workflows. Use them only after the shared preview foundation exists; they stop for missing prerequisites or failed verification and never start another target automatically. See `documentation/Guides/event-workspace-workflows.md` for activation, approval, and recovery.

### Maintaining agent guidance
- `AGENTS.md` is the single canonical repository guide. `.github/copilot-instructions.md` stays empty to avoid duplicate context; do not copy guidance into it.
- Keep this file to durable facts and conventions; put procedures in skills and feature details in `documentation/`.
- New skill: create `.agents/skills/<kebab-name>/SKILL.md`; `name` must equal the folder name; `description` (≤ 1024 chars) states what it does and "Use when…" triggers with concrete keywords (classes, kinds, paths). Add it to the table above.
- Keep skills short and procedural: steps, invariants, checklist. Link to `documentation/` instead of duplicating it; put large references or scripts beside `SKILL.md` and link them relatively.
- When code changes invalidate a skill, update the skill in the same change. Delete one-off plans or checklists once done rather than keeping them as skills.
- Third-party skills are installed with `npx skills add` and tracked in `skills-lock.json`; do not hand-edit them.
- Controller-level `AGENT.md` files hold local context; keep them consistent with this file.

## Next features

### Event workspace and composable previews
Evolve Reading Nook and My Content into an expandable, filesystem-style event browser with nestable previews that always render from signed event tags. Plan: `documentation/Reader/event-workspace-iteration-plan.md`. Skills: `event-workspace-contract`, `migrate-event-surface`, `add-event-viewer`.
## Backlog


### Favorite relays integration (kind 10012)

Investigate and implement support for NIP-51 favorite relay feeds (kind `10012`) so user favorite relays can augment content relay selection safely.

Goals:
- Parse user kind `10012` events (`"relay"` tags first; `"a"` relay-set refs optional in phase 2).
- Use favorites to augment **user-scoped** content relay lists (do not mutate global `relay_registry.content_relays`).
- Include kind `10012` in login/event sync kinds so it is fetched and persisted during user sync.
- Include kind `10012` in local user-context hydration subscription kinds so DB-first lookups can use it.

Suggested rollout:
1. Add `KindsEnum` constant for `10012`.
2. Add `10012` to `SyncUserEventsHandler::SYNC_KINDS`.
3. Add `10012` to `SubscribeLocalUserContextCommand::SUBSCRIBE_KINDS`.
4. Add a small service to read latest `10012` per user and extract normalized favorite relays.
5. Merge favorites into user-scoped content relay selection with limits and deduplication.

Guardrails:
- Keep favorites per-user only; never promote to system-wide relay registry defaults.
- Cap added relays (e.g. top 5-10) to avoid fan-out growth and worker/gateway pressure.
- Prefer relay health-aware ordering when possible.


### Special request: subdomain chat

Implement private chat rooms as subdomains with public chat within them.
See chat.md for details.

### Show alt-tag contents when kind unknown
When an event of an unknown kind is encountered, and content is empty, instead of showing a blank or generic placeholder, the application should display the contents of the 'alt' tag if it exists.


### Low data mode
Implement a low data mode that can be toggled in the settings. 
When enabled, this mode will hide article cover images in article lists, and make all media 'click to load'.

### Global admin suppression (ingestion-time bans)

NIP-09 (`deleted_event`) already hard-blocks re-ingestion of author-deleted events. A sibling layer should hard-block ingestion of content we (the instance operator) have decided to permanently drop — without overloading `deleted_event`, which carries NIP-09-specific semantics (author-signed, permanent, risk of round-tripping out as a real kind:5 if/when outbound is wired).

Design:

- Add a `banned_pubkey` table (hex pubkey, reason, added_at, added_by). Optionally extend the existing `HiddenCoordinate` entity (currently query-time only for magazine/book listings) to also participate in the ingestion check so coordinate-level bans hard-drop incoming events instead of just hiding them from listings.
- Wire a check into the same two hook points `EventDeletionService` uses: `GenericEventProjector::projectEventFromNostrEvent` and `PersistGatewayEventsHandler::__invoke`. When the incoming event's pubkey is in `banned_pubkey` (or its coordinate is in `hidden_coordinate`), drop silently — identical flow to the existing NIP-09 shadow-ban.
- Add a one-shot reaper command `bans:reap` (modelled on `events:replay-deletions`) that bulk-deletes already-stored rows from a newly banned pubkey across `event` / `article` / `highlight` / `magazine` (plus Redis view invalidation).
- Admin UX: CLI first (`admin:ban-pubkey <hex> --reason="…"`, `admin:unban-pubkey <hex>`), then an admin dashboard section alongside the existing hidden-coordinate management.

Non-goals — do **not** move these to hard deletion:

- Per-user mutes (NIP-51 kind 10000). These belong to the viewer; another user might want the content. Keep them as query-time filters.
- NSFW flagging. Already detected via `Event::isNSFW()` (content-warning, NIP-32 `L nsfw`, `t` hashtags). Keep as a render-time / low-data-mode gate so users can toggle visibility without losing data.

Deliverables: entity + migration + repository, shadow-ban hook, reaper command, two CLI commands, unit + Gherkin feature spec, docs at `documentation/Admin/global-suppression.md`.

### Deprecation notice: url validation

In `MediaAttachmentType`, on `Assert\Url`:

```
 message: "User Deprecated: Since symfony/validator 7.1: Not passing a value for the "requireTld" option to the Url constraint is deprecated. Its default value will change to "true"."
```
