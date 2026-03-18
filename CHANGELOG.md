# CHANGELOG

## v0.0.16
Feeds, walls, boards, whatever you want to call them.

- Reworked media discovery page (`/multimedia`) into a tabbed interface with Latest, Follows, and Interests tabs, mirroring the home feed pattern. Follows and Interests tabs are disabled for anonymous users.
- Added media follows support (kind 10020, NIP-68): fetches the user's multimedia follow list on login, with fallback to regular follows (kind 3) when no media follow list exists.
- Added `findMediaEventsByPubkeys()` and `findNonNSFWMediaEventsByPubkeys()` to EventRepository for querying media events across multiple followed authors.
- Restyled picture curation sets (kind 30006) as an Instagram-style grid with lightbox navigation (keyboard arrow keys + click).
- Restyled video curation sets (kind 30005) as a YouTube-style playlist with main player and scrollable sidebar list.
- Added Stimulus controllers: `media--media-discovery-tabs` (tab switching), `media--picture-gallery` (lightbox), `media--video-playlist` (playlist player).
- Fixed `/e/{identifier}` route rejecting `naddr1…` and `nprofile1…` URLs: the route regex only matched `nevent` and `note` prefixes, so NIP-19 `naddr` links (used by curation sets, articles, etc.) returned a 404 even though the controller already handled them.
- Improved curation set loading for media boards (kinds 30005/30006): missing referenced `e`-tag media events now load asynchronously through Messenger, and the page polls a lightweight sync-status endpoint so it can refresh once new media has been persisted instead of blocking the initial page render.
- Fixed `naddr` curation redirects landing on `/p/{npub}/curation/{kind}/{slug}` and then failing with “Curation set not found”: relay-fetched curation events are now persisted before redirect, and the curation route itself now falls back to a live relay lookup when the local DB does not have the container event yet.
- Fixed noisy and repeatedly reconnecting relay-auth/curation EventSource traffic: relay-auth now mounts only on the first post-login request when gateway warmup is actually dispatched and uses short polling for pending AUTH challenges, while curation sync now polls a lightweight status endpoint instead of holding a Mercure subscription open.
- Improved picture collection lightbox for kind 30006 boards: it now opens per event (not across the whole grid), hides navigation for single-image events, navigates within multi-image event galleries, and shows event metadata in the lightbox (title, content, and publisher).
- Added a Collections tab to `/multimedia` that lists DB-backed picture and video curation sets and links directly to their gallery/playlist pages.
- Fixed picture lightbox details rendering for kind 30006 boards: raw image URLs placed in event content now remain clickable links in the metadata panel instead of being auto-rendered as embedded images.
- Stabilized curation page layout for article, picture, and video collections: added a shared curation page shell with consistent hero/content widths and extra overflow containment so grids and playlists no longer break the center column layout.
- Fixed home feed tab switching: loading spinner now shows immediately when switching tabs, in-flight requests are cancelled when a new tab is clicked, and a 20-second timeout with automatic tab rollback prevents the UI from getting stuck in a broken state when a tab (especially Follows) takes too long to load.
- Fixed dangling curl/WebSocket processes: `NostrRelayPool::closeRelay()` and `closeAll()` now actually disconnect the underlying relay connections instead of just unsetting array entries. `publishDirect()` and `sendDirect()` error paths now disconnect relays on failure. Added `__destruct()` to `NostrRelayPool` as a safety net for long-lived FrankenPHP workers. Set `default_socket_timeout = 30` in PHP ini to cap stream-based HTTP calls. Added Symfony HTTP client defaults (`timeout: 15`, `max_duration: 60`) to prevent unbounded curl handles.- Fixed Unfold cache warming timeout: coordinate pubkeys from event `a` tags were not normalized to lowercase, causing graph lookups to silently miss data (case mismatch with `current_record.coord`). The relay fallback path then hung without warning. Normalization now applied at `SiteConfig`, `SiteConfigLoader`, `CategoryData`, `GraphLookupService`, and `ContentProvider` boundaries. Relay fallback wrapped in try/catch to return empty gracefully instead of blocking.
- Fixed reading list / magazine publish returning 500 with no error logs: the `magazine-{slug}` Redis cache write in `publishIndexEvent` swallowed all exceptions silently and was the sole cause. Removed the dead cache write entirely — no consumer reads from that key (public routes use `magazine-index-{slug}` via `RedisCacheService`, Unfold uses `GraphLookupService`). Also removed the `magazine_slugs` Redis set write (admin dashboard now queries the DB directly). Added try-catch with logging around Doctrine persist. Added missing `extractAndSetDTag()` call so the `d_tag` column is populated for published index events.
- Rewrote `MagazineEditorController` to read/write from the `event` table instead of the dead `magazine-{slug}` Redis cache. Search now uses `ArticleSearchInterface` (works with or without Elasticsearch) instead of raw `FinderInterface`. Mutations persist a new event version to the DB with `extractAndSetDTag()`.
- Cleaned up `NostrEventFromYamlDefinitionCommand`: removed dead `magazine-{slug}` cache and `magazine_slugs` set writes, events now persisted to the `event` table with `extractAndSetDTag()`.
- Added `d_tag` column to `event` table with composite coordinate index `(kind, pubkey, d_tag)` — enables fast coordinate-to-event lookups without scanning JSONB tags. Backfill migration populates existing parameterized replaceable events (kinds 30000–39999).
- Deprecated `eventId` column on `Event` entity — `getEventId()` now returns `$this->id`, `setEventId()` aliases to `setId()`. Column kept for backward compatibility; will be dropped in a future release.
- Added `RecordIdentityService` — single authority for canonical coordinate strings, record UID generation, and entity type classification. All graph identity derivation flows through this service.
- Added `parsed_reference` table and `ReferenceParserService` — normalized extraction of `a` tag references from event tags, with structural/relation classification. Backfill command: `dn:graph:backfill-references`.
- Added `current_record` table and `CurrentVersionResolver` — tracks the current (newest) event version for each replaceable coordinate using atomic upsert with tie-break. Backfill command: `dn:graph:backfill-current-records`.
- Added `GraphLookupService` — recursive CTE-based tree traversal for magazine/category/article resolution from local PostgreSQL, eliminating relay round-trips for structure queries. Primary consumer: Unfold cache warming.
- All event creation call sites now populate `d_tag` via `extractAndSetDTag()` for new events going forward.
- Refactored Unfold `ContentProvider` to use `GraphLookupService` as primary path for tree traversal, with relay round-trips as fallback. Cache warming and first-visit article loading now resolve from local DB instead of WebSocket requests.
- Fixed graph layer missing articles: `current_record` backfill only scanned the `event` table, but articles live in the `article` table. Added auto-heal in `GraphLookupService::resolveChildren` that lazily populates `current_record` from the `article` table when target coordinates are missing. Updated `fetchEventRows` to fall back to `article.raw` JSON for event data. Updated `dn:graph:backfill-current-records` to include a second pass over the `article` table.
- Fixed duplicate articles in Unfold categories: `parsed_reference` accumulated duplicate rows when backfill or raw event ingestion ran multiple times. Added `DISTINCT ON` deduplication to `resolveChildren` and `resolveDescendants` queries. Made `EventIngestionListener::processRawEvent` and `GraphBackfillReferencesCommand` idempotent (delete-before-insert).
- Added `EventIngestionListener` — automatically updates `parsed_reference` and `current_record` tables when new events are persisted, keeping graph data current without periodic backfills.
- Optimized `EventRepository::findByNaddr` to use the `d_tag` column index instead of JSONB scanning.
- Reduced worker intensity: profile refresh worker now only processes stale profiles (not refreshed in 2+ hours) instead of loading all users every cycle.
- Reduced worker intensity: split Messenger into two separate consumers — high-priority (articles, comments, media) and low-priority (profiles, relay lists, sync) — so heavy background jobs no longer starve time-sensitive handlers.
- Reduced worker intensity: login sync chain is now throttled — relay list warming and event sync are skipped if the user was synced within the last 30 minutes, preventing redundant heavy relay queries on tab refreshes or re-logins.
- Reduced worker intensity: event sync on login now only fetches events from the last 24 hours instead of all-time, reducing relay load by orders of magnitude.
- Reduced worker intensity: cron job frequencies adjusted — article post-processing from 5→10 min, highlight fetching from 15→30 min, magazine projection from 10→30 min.
- Reduced worker intensity: profile refresh interval increased from 100 min to 4 hours, with batch size reduced from 100 to 50.
- Reduced FrankenPHP CPU usage: switched JIT from tracing to function-level (1255) — tracing JIT causes persistent high CPU in long-lived worker processes.
- Reduced FrankenPHP CPU usage: capped worker threads to 4 in production (default is 2× CPU cores, which over-provisions on multi-core servers).
- Reduced FrankenPHP CPU usage: switched Caddy compression from zstd+brotli+gzip to gzip-only — brotli is 5-10× more CPU-intensive than gzip.
- Reduced FrankenPHP CPU usage: added immutable cache headers for fingerprinted static assets, avoiding repeated compression and re-reads.
- Reduced FrankenPHP CPU usage: added Mercure Bolt cleanup_frequency and write/dispatch timeouts to reduce embedded hub overhead.
- Reduced FrankenPHP CPU usage: lowered production memory_limit from 512M to 256M (worker mode reuses processes, lower limit reduces GC pressure).
- Added front page for logged-in users: a tabbed interface with Latest, Follows, Interests, Podcasts, and News Bots tabs. Each tab loads articles dynamically. Podcasts and News Bots tabs are powered by configurable follow packs (kind 39089 events).
- Added admin interface for follow pack management: assign kind 39089 follow pack events as sources for the Podcasts and News Bots home feed tabs.
- Reorganized the draft support center into shorter help-center-style articles with focused reader, writer, identity, billing, and FAQ guides for the future support magazine.
- Added Translation Helper (`/translation-helper`) for translating Nostr long-form articles. Import by naddr or coordinate, edit side-by-side with the original markdown, and publish as a new kind 30023 event with an `a`-tag referencing the original, NIP-32 language labels, and zap-split crediting the original author.


## v0.0.15
Mentions, embeds, and uploads.

- Added a Media Manager.
- Added NIP-05 lookup to user search: when a NIP-05 identifier (e.g. `bob@example.com`) is entered, the well-known endpoint is queried to resolve the hex pubkey, and the matching user is returned at the top of results.
- Added Mentions tab in the editor left sidebar: search users by name/NIP-05 and insert `nostr:npub1…` mentions at the cursor. Corresponding `p` tags are auto-generated on publish per NIP-27.
- Added Embeds tab in the editor left sidebar with three sections: profile embed (user search → `nostr:npub1…` card), article embed (article search → `nostr:naddr1…` card), and raw identifier paste (accepts `note1…`, `nevent1…`, `naddr1…`, `npub1…` codes).
- Added media tab in the editor left sidebar.
- Auto-generate `p`, `e`, and `a` tags from `nostr:` references in article content at publish time (both client-side in JS and server-side in PHP), per NIP-27. Deduplication prevents duplicate tags.
- Added NIP-19 TLV encoding/decoding utilities to `nostr-utils.ts`: `decodeNip19()`, `encodeNprofile()`, `encodeNaddr()`, and `extractNostrTags()` for full bech32 entity support.
- [Bug] Fixed cron.

## v0.0.14
Ripped out relay management, again, trying something different. 

- Implemented i18n translations: extracted all user-facing text into YAML translation files, added locale switching via footer language selector. English remains the default.
- Relay pool management Phase 1: centralized relay configuration via `RelayRegistry` (replaces 4 scattered hardcoded constants), Redis-backed persistent health tracking via `RelayHealthStore`, and consolidated 3 near-identical subscription loops (~600 lines) into one parameterized `subscribeLocal()` method with heartbeat reporting.
- Relay pool management Phase 2.1: `UserRelayListService` — stale-while-revalidate relay list resolution with DB write-through (cache → DB → network → fallback). Replaces `AuthorRelayService` entirely (deleted) and the fragmented relay resolution in NostrClient. All 11 consumers migrated. Network fetch logic inlined. Persists kind 10002 events to the Event table on successful network fetch.
- Relay pool management Phase 2.2: async relay list warming on login — `UpdateRelayListMessage` dispatched via Messenger on `LoginSuccessEvent`, handled on the low-priority queue so relay data is pre-warmed by the time the user navigates to follows/editor.
- Relay pool management Phase 2.3: relay gateway — persistent WebSocket connection pool with NIP-42 AUTH support. Long-lived `app:relay-gateway` process maintains authenticated connections to external relays, keyed by (relay, user) pair. FrankenPHP request workers communicate via Redis Streams. AUTH challenges are signed by the user's browser via Mercure SSE roundtrip. Feature-flagged via `RELAY_GATEWAY_ENABLED`.
- Relay pool management Phase 3: admin relay dashboard with pool visibility (per-relay health scores, AUTH status, latency, last success/failure), subscription worker heartbeat monitoring, and gateway status. Health-based relay ranking — external relays are now sorted by health score (success rate × inverse latency) when building relay sets. Formalized `RelayPoolInterface` extracted from `NostrRelayPool`.
- Relay gateway: switched from persistent shared connections to on-demand connection model. Connections are opened lazily when first needed, kept alive for a configurable idle TTL (default 5 min), then closed. Eliminates startup connection failures, idle resource waste, and noisy reconnection churn.
- Relay gateway: inline connection opening for queries and publishes. When no connection exists for a target relay, the gateway opens one inline (blocking) and sends the REQ/EVENT immediately. Replaces the broken deferred pattern where one-per-tick connection opening + 56-second settle cycle caused all client requests to time out. Connections are reused for subsequent requests to the same relay.
- Relay gateway: optimistic send — EVENTs and REQs are now sent immediately upon connection, without waiting for the 1-second AUTH settle window. Most relays don't require AUTH; those that do will respond with CLOSED:auth-required, which the new NIP-01 CLOSED handler properly records.
- Publishing: moved all publish flows back to direct relay connections (bypasses gateway entirely). Each relay is contacted independently so one failure cannot block the others. Fixes the gateway publish timeout issues where connections took too long to open/settle. Gateway is still used for reads/queries. Affects: article editor, broadcast, comments, interests, magazine, forum posts.
- NIP-01 compliance: tag filter passthrough (`#e`, `#p`, `#t`, `#d`, `#a`) — previously silently dropped in gateway and local relay re-routing, causing queries to return unfiltered results. Fixed in both `RelayGatewayCommand` and `NostrRequestExecutor::buildFilterFromArray`.
- NIP-01 compliance: CLOSED and NOTICE handling — CLOSED now properly records errors and calls `recordFailure()` instead of being treated as a successful EOSE. NOTICE now logs the message without trying to match a subscription ID.
- [Bug] Fixed messenger worker crash loop: removed dangerous direct-connection fallback when gateway returns no events (TLS+AUTH exceeded 15s execution limit). Capped gateway query timeouts at 8s.
- [Bug] Fixed relay URL normalization in `GatewayConnection::buildKey` — trailing slash differences between config and user relay lists caused shared connection lookup misses.
- [Bug] Fixed `xRead('$')` initialization causing gateway to never consume stream messages. Added `getStreamLastId` via `xRevRange` for robust initialization.
- [Bug] Fixed Error: Failed to fetch article: The EntityManager is closed.
- [Bug] Fixed vanity name subscription settings page.

## v0.0.13
All improvements were gathered on the way, while trying to get rid of the persistent errors.

- Improved db handling of articles.
- Updated bookmarks page with better fetch and styling.
- Updated article loading.
- Enabled frankenphp.
- Added interests editor on the My Interests page: users can create or edit their kind 10015 interests list by selecting popular tags or adding custom ones, then sign and publish to Nostr relays.
- [Bug] Fixed Nip05 verification for own vanity names.
- [Bug] Fixed uncertain math rendering with KaTeX.
- [Bug] Fixed 502 errors on article pages for anonymous users.
- [Bug] Fixed too-short caching TTLs for articles and highlights, that caused empty highlights list.


## v0.0.12
More metadata is better.  

- Fixed article links on tag pages to use the correct `/p/{npub}/d/{slug}` URL format instead of `/article/d/{slug}`.
- Removed the floating ReadingListQuickAdd widget (component, template, and CSS) — replaced by other functionality.
- Added support for extra metadata tags on articles: source references (`r` tags) and media attachments (`imeta` tags) in the article editor, event builder, and event parser.
- Display category/reading-list summaries on magazine front category headers, collections list cards, reading list pages, and Unfold category pages.
- Filter bot/RSS-type authors out of the Latest Articles feed (denylist + profile bot flag).
- Added prev/next article navigation cards at the bottom of article pages when the article belongs to a reading list or curation set.
- Added a floating "Back to top" button that appears when scrolling down on any page.


## v0.0.11
Mostly quality of life improvements.

- Removed reading lists from the editor sidebar to reduce clutter; sidebar now shows only drafts and articles.
- Added "My Content" page (`/my-content`) — a unified file-manager view for managing articles, drafts, and reading lists.
- Added "My Content" link to the left navigation under the Newsroom section.
- Split sidebar navigation into segmented sections (Discover, Newsroom, Create) with divider labels.
- Upgraded magazine wizard to a 4-step flow: Setup → Categories → Articles → Review & Sign.
- Added live cover preview panel to the magazine setup step.
- Added image upload support to the magazine setup and category steps.
- Added sortable (drag-to-reorder) categories in the wizard.
- Replaced raw naddr coordinate input with a dropdown of user's existing reading lists.
- Added login prompt and desktop device recommendation to the wizard.
- Added basic zap invoices to UnfoldBundle.
- Implemented AsciiDoc parser for kind 30041.
- Updated footer.
- Added nostrconnect uri to the signer flow, so you can log in on the same device.
- [Bug] Fix a bug in magazine wizard, so now you get form errors instead of a broken page.
- [Bug] Fixed Reading List edit loading bug, so now you can actually edit your reading lists.


## v0.0.10
Publications as first-class citizens.

- Introduced publications on subdomains MVP.
- [Bug] Fixed image upload in the editor.
- [Bug] Fixed routing for vanity names.


## v0.0.9
Starting to look like a real product, isn't it?

- Introduced Vanity Names (NIP-05).
- Introduced Active Indexing.
- Updated static pages to reflect changes.
- Updated relay communications.
- Added JSON-LD metadata to article and magazine pages.
- Added a "Support" card.
- [Bug] Fixed a host of bugs in the article and magazine publishing process.

## v0.0.8
Toast on toast, and event in event.

- Remember me.
- Favicon is now there.
- UnfoldBundle now loads a magazine on a configured subdomain.
- Show embeds of nostr events in articles.
- Broadcast option.
- Show a stack of toasts instead of replacing the previous one.
- Showing a placeholder when an article is not found.
- Better handle comments.
- Added generic 'alt' tags to index events.
- Admin dash update.
- Overhauled Caddy config. 
- [Bug] Comments never loaded... because configuration was a mess.
- [Bug] Button didn't open a dialog on login page.


## v0.0.7
Lists that actually list things. Revolutionary.

- Reading lists now load existing lists.
- Now possible to add articles to magazines and reading lists by naddr.
- [Bug] Refactoring metadata introduced a bug in profiles, showing name instead of display name.
- [Bug] Add-to-list button defaulted to extension, now honors login method.


## v0.0.6
Testing revealed some issues. What a shocker. 

- Non-blocking user profile data sync, typed metadata.
- Show/hide long highlights context.
- [Bug] Fixed scrolling in editor.
- [Bug] Fix signer flow in magazine setup.
- [Bug] Fixed squished tabs on mobile.
- [Bug] Fixed reading list wizard buttons and general publishing flow.


## v0.0.5
Navigating to nostr ids made easier.

- Extended search to nostr idents, so you can paste a nostr npub, note, nevent or naddr to navigate to that profile, event or article.
- Updated profiles, implemented background fetch.
- Made publishing magazines available.
- Added zap buttons to articles.
- Brought back zaps as comments.
- Made multimedia more resilient.


## v0.0.4
Deployment used to be a remake of Minesweeper. Now it's more like Darts.

- Updated deployment and build, added documentation.
- [Bug] Fixed Elasticsearch feature flag.
- [Bug] Fixed article title sync in editor.


## v0.0.3
We know you have better things to do than waiting around for the page to load.

- Refactored article editor.
- Removed deprecated Nzine implementation.
- Added a user profile index to Elasticsearch.
- You can now include a cover image in reading lists.
- Added a feature flag for Elasticsearch integration.
- Implemented a new caching object to speed up page loads.
- Extended article entity with parsed HTML content.
- Added a worker for ingesting articles from the local relay.
- [Bug] Fixed share links
- [Bug] Fixed bunker signer

## v0.0.2 
Let's pretend we finally know what we are doing here.

- Initial changelog created.
- Local relay.

## v0.0.1
We won't go into detail here. Most of it was just learning the ropes.

- Initial development setup with lots of wrong turns.
