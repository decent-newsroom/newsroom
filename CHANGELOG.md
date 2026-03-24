# CHANGELOG

## v0.0.23

- Added admin "Hide/Unhide" toggle for magazines: admins can hide specific magazine coordinates (e.g. test events or malformed publications) from the public newsstand, bookshelf, and magazines manifest. Hidden magazines remain visible in the admin panel with a "hidden" badge and an "Unhide" button.
- [Bug] Fixed newly created magazines not appearing on the newsstand: the magazine wizard saved events to the database but did not update the graph layer (`current_record`), which the newsstand queries. Added `EventIngestionListener::processEvent()` call after persisting magazine/reading-list events.
- [Bug] Fixed `wss://relay.decentnewsroom.com` in user relay lists (NIP-65) not being remapped to the local strfry.


## v0.0.22
Admin roles, relay gateway, highlights, and magazine journey.

- Added "Users with Roles" overview table to the admin roles page (`/admin/role`): displays all users that have any roles assigned beyond the default `ROLE_USER`, showing avatar, name, npub, role badges (color-coded by role type), and per-role remove actions. Includes a new route for removing arbitrary roles from any user.
- Added Relay Gateway Status admin page (`/admin/relay/gateway`): shows all relays ever seen in the health store (configured and discovered via user relay lists), gateway process heartbeat, Redis stream status, and per-relay mute/unmute/reset controls. Muted relays are excluded from all relay operations (queries, publishes, health scoring) but can be unmuted at any time. The local strfry relay cannot be muted.
- Removed highlight relay refresh from article page load: highlights are now served purely from Redis/DB cache with no async refresh dispatch during the web request. Relay ingestion happens entirely via cron jobs (`app:fetch-highlights`, `app:cache-latest-highlights`) and relay subscription workers.
- Added Magazine Journey: a 6-step onboarding wizard (`/blog/start`) that guides creators through setting up a blog/magazine on Nostr — from syncing articles from relays, through magazine setup and category organization, to choosing a free subdomain and launch confirmation. Includes a marketing landing page (`/start-blog`), Mercure-powered sync progress, inline subdomain availability checker, and full English translations.
- [Bug] Fixed `dn:graph:audit` stalling on production and ignoring Ctrl+C: added PCNTL signal handling for graceful interruption, set PostgreSQL `statement_timeout` (120s) so no single query blocks forever, batched the Check 1 LATERAL join (was scanning all `current_record` rows in one query), replaced `tags::text LIKE` with JSONB containment (`@>`) for GIN index use, dropped unnecessary `ORDER BY` from Check 2, eliminated N+1 reference count queries with a single batched `GROUP BY`, added progress bars and status messages to all phases, switched repair to bulk inserts with tags carried forward from audit. Same `tags::text LIKE` fix applied to `dn:graph:backfill-references`.

## v0.0.21
Graph layer, highlights, and various bug fixes.

- Optimized highlight lookup on article pages: added Redis caching layer (Redis → DB fallback) eliminating 3 DB queries per page load on cache hit, consolidated remaining DB queries into a single call, moved deduplication from PHP to the database, and added a compound index on `(article_coordinate, cached_at)`.
- Added Bookshelf page (`/bookshelf`) — lists all books (kind 30040 events referencing kind 30041 content sections), with navigation link alongside Newsstand.
- [Bug] Fixed hybrid collections (top-level 30040 → sub-level 30040 → 30041 chapters) failing to display chapters on the newsstand: `FeaturedList` and `magCategory` now detect when a sub-index's `a` tags reference kind 30041 events and fetch/display them as chapters instead of searching for articles.
- Deprecated search query counting, throttling, result limitations, and credit transaction records. Search is now unrestricted for all users (anonymous and authenticated alike) with no credit cost. The credits system (`CreditsManager`, `RedisCreditStore`, `CreditTransaction`, `GetCreditsComponent`) and admin transaction dashboard are marked `@deprecated` and will be removed in a future release.
- [Bug] Fixed images added via QuillJS editor toolbar not surviving round-trip through markdown: `![alt](url)` syntax was parsed as a link instead of an image embed, causing images to be lost or mangled on mode switch.
- [Bug] Fixed images inserted from the sidebar media manager showing as raw markdown text in the QuillJS editor instead of rendering as visual image embeds.
- Added `dn:graph:audit` command — cron-safe consistency checker that detects drift between `event`/`article` tables and graph tables (`current_record`, `parsed_reference`). 
- Added `dn:graph:rebuild-record` command — single-coordinate surgical repair that replays newest-wins resolution and re-parses references. 
- Added `GraphMagazineListService` — graph-backed replacement for `MagazineRepository` listing queries. Uses `current_record` + event metadata to list top-level magazines, filter by pubkey, and count. 
- Deprecated `MagazineProjector`, `ProjectMagazineMessage`, `ProjectMagazineMessageHandler`, and `ProjectMagazinesCommand`. The graph layer (`current_record` + `parsed_reference` + `GraphLookupService`) replaces periodic magazine projection.
- [Bug] Fixed highlights not showing on article pages: removed `HIGHLIGHTS_ENABLED` feature gate that was left on after the async refactor resolved the original performance issue.
- [Bug] Fixed highlight-to-article coordinate mismatch: all ingestion paths now extract the canonical coordinate from the event's own `a` tag, ensuring consistency between cron-fetched and async-refreshed highlights.
- Improved highlight relay coverage: highlights are now fetched from both the local strfry relay and default relays instead of only the local relay.
- Added highlighting UI: logged-in users can select text in an article and click "Highlight" to publish a NIP-84 kind 9802 event, which is signed via the user's Nostr signer, published to relays, and persisted locally for immediate display.
- [Bug] Fixed `current_record` table not existing: auto-generated migration `Version20260323161221` incorrectly dropped the graph-layer tables (`current_record`, `parsed_reference`) because they are raw-SQL tables not managed by Doctrine ORM. Removed the destructive statements, added a recovery migration, and configured `doctrine.dbal.schema_filter` to exclude these tables from future diffs.
- [Bug] Fixed user-muted pubkeys (kind 10000, NIP-51) not being excluded from the latest articles feed: the personal mute list was synced on login but never applied during feed filtering. Added `UserMuteListService` to read the user's mute list from the database and applied it as a post-filter on the latest tab (home feed), discover page, and latest-articles page.
- [Bug] Fixed Redis view cache silently losing data due to compression flag desync: the `:compressed` flag was stored as a separate Redis key that could outlive or expire independently of the data key, causing `gzuncompress()` to be called on raw JSON (or raw JSON to be decoded from compressed binary), returning `null` and making the cache appear lost. Replaced with auto-detection from data content (JSON starts with `[`/`{`, zlib starts with `0x78`). Corrupt entries are now self-healing (deleted on read failure, rebuilt by next cron run).
- [Bug] Fixed `unfold:cache:warm` not refreshing with latest articles in categories: the warm command only invalidated the SWR cache and re-read from the graph layer (`current_record` + `parsed_reference`), which could be stale if the latest category events hadn't been ingested from relays.


## v0.0.20
Bugs, downtime, limits. 

- Moved `/preview/` route to `/api/preview/` so it is not counted in page visits.
- [Bug] Fixed periodic 504 Gateway Timeout caused by FrankenPHP worker pool exhaustion: the `/latest-articles` route's cache-miss fallback was synchronously fetching 150 articles from Nostr relays with a 5-minute `set_time_limit(300)`, blocking one of only 4 workers for up to 5 minutes. Replaced with a fast database search fallback (same as `/discover`). The cron job (`app:cache_latest_articles`, every 15 min) handles relay fetching asynchronously.
- [Bug] Added global `max_execution_time = 30` to PHP config as a safety net: prevents any single request from blocking a FrankenPHP worker indefinitely.
- [Bug] Added Caddy `request_timeout 30s` to the Caddyfile: kills connections after 30s even if PHP is stuck on a relay WebSocket mid-read, preventing indefinite connection holds.
- [Bug] Reduced `default_socket_timeout` from 30s to 15s: stream-based HTTP calls (e.g., `file_get_contents`) now fail faster against unresponsive servers.
- [Bug] Reduced default per-relay WebSocket timeout from 5s to 3s in `TweakedRequest`: with 3 relays tried serially, worst-case blocking drops from 15s to 9s.
- [Bug] Added `set_time_limit(15)` to `EventController`: event/nevent/naddr lookups that miss the database now cap relay round-trips at 15s.
- [Bug] Reduced `set_time_limit` for `/article/{naddr}` from 25s to 15s.
- [Bug] Removed redundant `set_time_limit(30)` calls from article and draft view controllers (DB-only operations already covered by global 30s limit).


## v0.0.19
Actions.

- Added 'Bookmark' action to articles: logged-in users can now bookmark/unbookmark articles directly from the article page.
- [Bug] Fixed bookmark deduplication: kind 10003 is replaceable.
- Consolidated article actions into a single dropdown.
- Removed deprecated components superseded by the article actions dropdown.
- Wired all article actions dropdown feedback (copy, bookmark, broadcast) into toast notifications instead of inline status text.


## v0.0.18
User settings and event management.

- Added external referrers list to admin analytics page: a new "External Referrers (Last 30 Days)" table shows only referrers whose URL does not contain the configured base domain, making it easy to identify traffic from external sources.
- Fixed profile publishing losing pre-existing kind 0 tags: the JS controller now starts from the existing event's tags and only replaces form-managed fields (`display_name`, `name`, `about`, `picture`, `banner`, `nip05`, `lud16`, `website`), preserving all other tags (e.g. `client`, `proxy`, `i`, `alt`, custom tags from other Nostr clients). The raw profile event display now shows the complete Nostr event (id, pubkey, kind, created_at, content, tags, sig) instead of just the content field.
- Fixed "Sync from relays" not fetching kind 3 (follows) and other replaceable events older than 24 hours. The sync pipeline applied a `since = now - 86400` filter to all event kinds, skipping replaceable events (kind 0, 3, 10000–19999, 30000–39999) that hadn't been updated recently. Added `fullSync` flag to `UpdateRelayListMessage`: the settings "Sync from relays" button always sends `fullSync: true` (no time restriction), and the login flow sends `fullSync: true` on first login (`lastMetadataRefresh === null`) but `false` on subsequent logins to keep the 24-hour optimization.
- Fixed kind 0, 3, and other user context events not appearing in the event table when async workers haven't run. The settings page now backfills ALL missing critical user context events (kind 0 metadata, kind 3 follows, kind 10002 relay list, and all other `USER_CONTEXT` kinds) in a single `fetchUserContext()` relay round-trip when any are missing from the DB. The follows feed (`HomeFeedController`, `FollowsController`) now falls back to `UserProfileService::getFollows()` (DB-first + relay fallback with persistence) when kind 3 is missing, instead of showing an empty "not following anyone" state.
- Kind 0 metadata events fetched from relays during profile projection updates (single and batch) are now persisted to the Event table via `GenericEventProjector`, ensuring the event store stays in sync with user entity updates. Previously only the User entity was updated while the raw event was discarded.
- Added "Sync from relays" button on the settings Events tab, allowing users to manually trigger a full relay sync (relay list revalidation + batch event fetch) on demand, not only on login. Uses the same async pipeline (`UpdateRelayListMessage` → `SyncUserEventsMessage`) as the login flow.
- Added NIP-01 replaceable event semantics to `GenericEventProjector`: kind 0, 3, and 10000–19999 events now replace older versions for the same pubkey+kind; kind 30000–39999 events replace older versions for the same pubkey+kind+d_tag. Incoming stale events are skipped when a newer version already exists in the DB. Ensures kind 0 metadata updates propagate correctly through the user context pipeline.
- Fixed settings profile tab showing empty fields: profile form now reads from the User entity (populated by `UserMetadataSyncService` on login) instead of the event table. Publishing a profile update also writes back to the User entity immediately, so changes are visible without waiting for the next login sync.
- Profile publishing now preserves unknown kind 0 fields: the JS controller starts from the existing raw content JSON and merges form edits on top, so fields DN doesn't manage (e.g. `bot`, `pronouns`, custom fields from other clients) are retained. A collapsible "Raw Profile JSON" section at the bottom of the Profile tab shows the full kind 0 content for transparency. Kind 0 events are now persisted to the event table (backfilled from cache on settings page load if missing), so the Events tab consistently shows metadata status and the raw content is read from persistent storage rather than volatile cache.
- Fixed follows feed showing "not following anyone" for logged-in users: added user context hydration worker (`user-context:subscribe-local-relay`) that subscribes to the local strfry relay for user identity and social graph events (kinds 0, 3, 5, 10000–10063, 30003–30015, 30024, 34139, 39089) and persists them to the database via `GenericEventProjector`. Previously these events were forwarded to strfry by `SyncUserEventsHandler` on login but no subscription worker picked them up for DB persistence, so DB-only lookups in the follows tab, bookmarks page, settings, and other controllers found nothing. Integrated into `app:run-relay-workers` with `--without-user-context` opt-out.
- Added user settings page (`/settings`) with three tabs: **Profile** (edit and sign-and-publish kind 0 metadata with both tags and JSON content for compatibility), **Events** (dashboard showing all Nostr events used by the newsroom — kind 0 metadata, kind 3 follows, kind 10002 relay list, kind 10003 bookmarks, kind 10015 interests, kind 10020 media follows, kind 10000 mute list, kind 10001 pin list, kind 10063 Blossom servers, kind 30015 interest sets, kind 39089 follow packs — with inline setup links for missing interests and media follows), and **Subscriptions** (vanity name, active indexing, and publication subdomain status with management links). Linked from the logged-in user menu. Includes Stimulus controllers for tab switching and profile publishing, and translations for all 5 locales.
- Moved muted-author exclusion earlier in the latest feed pipeline: `LatestArticlesExclusionPolicy` now injects `MutedPubkeysService` and exposes `getAllExcludedPubkeys()` which merges the config-level deny-list with admin-muted users. All latest-feed code paths (`CacheLatestArticlesCommand`, `/discover`, `/latest-articles`, home feed latest tab) now push the unified exclusion list into the initial DB/relay query so that excluded authors never consume the row budget. The relay fallback path in `/latest-articles` also over-fetches (150 → filter → trim to 50) to compensate for post-fetch filtering.
- Optimized follows feed loading: switched from relay-based follow list resolution (with slow network fallback) to DB-only lookup. The kind 3 follow list is read directly from the local `event` table (populated on login sync), articles are queried via a new `ArticleRepository::findLatestByPubkeys()` method with a composite `(pubkey, created_at DESC)` index, and the unused `FollowsRelayPoolService::getPoolForUser()` call was removed. Eliminates all relay round-trips from the follows tab — response is now purely local DB + Redis metadata cache.
- Changed `UserProfileService::getInterests()` to only look up the local database (populated from local strfry relay) instead of falling back to remote relay queries. If the interests event is not in the DB, an empty array is returned — the user probably hasn't published one yet. Removed the `$relays` parameter from `getInterests()` and `getUserInterests()`, and updated all callers in `ForumController`, `HomeFeedController`, and `MediaDiscoveryController`.
- Added chat push notifications via Web Push API (RFC 8030). Browser push notifications for chat messages when users are not actively viewing a chat group. Includes VAPID key configuration, `ChatPushSubscription` entity for multi-device subscriptions, `SendChatPushNotificationMessage` async Messenger handler with 30-second per-group throttle, `ChatPushController` for subscription management, `ChatWebPushService` for non-blocking dispatch, Service Worker (`chat-sw.js`) with tab-visibility suppression and notification-click focusing, Stimulus `chat_push_controller` for permission prompting, per-group notification mute toggle on `ChatGroupMembership`, and settings page with mute controls. Push payload contains only group name and sender display name for privacy. Requires `minishlink/web-push` package.
- Added dual-auth support for ChatBundle: chat admins now use self-sovereign Nostr keys (NIP-07/NIP-46) via the main DN login flow, while invited users keep custodial identities. Added `ChatMainAppAuthenticator` for cross-subdomain session sharing, `mainAppUser` FK on `ChatUser` with `isCustodial()` helper, client-side event signing path in the Stimulus controller, pre-signed event endpoints (`/messages/signed`, `/hide/signed`, `/mute/signed`) with server-side validation, and admin panel npub-based user creation. Session cookie scoped to base domain for subdomain access.
- Added ChatBundle: self-contained private community chat module with custodial Nostr identities, invite-only access, and relay-only message storage. Uses NIP-28 kind 42 for chat text messages, NIP-28 kinds 40/41 for channel setup, and kinds 43/44 for moderation. Includes 7 entities (ChatCommunity, ChatUser, ChatGroup, ChatGroupMembership, ChatCommunityMembership, ChatInvite, ChatSession), dedicated strfry-chat relay instance, AES-256-GCM key encryption, separate security firewall with cookie/session auth, admin CRUD controllers, Mercure SSE real-time updates, Stimulus chat controller, and relay-native moderation (kind 43/44 filtering).


## v0.0.17
Fetch in batches.

- Added automatic role promotion: any npub that publishes an article receives `ROLE_WRITER`, and anyone publishing a reading list or magazine receives `ROLE_EDITOR`. Introduced `UserRolePromoter` service to centralize role-assignment logic. Promotion is applied in the article editor, `ArticleEventProjector` (relay-ingested articles), `MagazineWizardController` (reading list/magazine publishing), and `GenericEventProjector` (relay-ingested kind 30040 events). Only users with an existing account (have logged in) are promoted; unknown npubs are silently skipped.
- Fixed `Maximum execution time of 10 seconds exceeded` error when loading articles via `/article/naddr1...` links. Increased `set_time_limit` from 10s to 25s to accommodate two sequential gateway relay queries (primary + fallback, each up to 10s). The controller now checks the database first and skips the relay round-trip entirely when the article is already saved.
- Improved relay selection for naddr article fetches: the primary relay set now combines naddr hint relays, the author's declared write relays (kind 10002), and the local relay into a single best-guess set, instead of querying hint relays alone. This finds articles in one round-trip more often, reducing the need for the slower fallback query.
- Fixed search/paste of `naddr1` links for non-article kinds (e.g. curation sets, media events) showing a "not an article" error page. All `naddr` identifiers now route through the generic event controller (`/e/...`), which handles each kind appropriately — articles redirect to their article page, curation sets to their dedicated views, and all other kinds render the event detail page.
- Changed `SyncUserEventsHandler` to forward fetched events to the local strfry relay instead of persisting directly to the database. Removed `EntityManagerInterface`, `EventRepository`, `ProfileEventIngestionService`, and `EventIngestionListener` dependencies. Events are now pushed to strfry via WebSocket and ingested by the existing subscription workers (articles, media, magazines) and on-demand DB-first-with-relay-fallback service methods.
- Added `KindBundles` class (`src/Enum/KindBundles.php`) with named groups of related Nostr event kinds (USER_CONTEXT, ARTICLE_SOCIAL, AUTHOR_CONTENT) and helpers (`groupByKind`, `latestByKind`, `categorizeArticleSocial`) for batch relay fetching.
- Added `AuthorContentType::fromKind()` reverse-lookup method to map a Nostr event kind back to its content type.
- Added `UserProfileService::fetchUserContext()` — fetches all user context kinds (0, 3, 5, 10000, 10001, 10002, 10003, 10015, 10020, 10063, 30015) in a single relay REQ. Updated `getFollows()`, `getInterests()`, and `getMediaFollows()` relay fallback paths to use the combined fetch, reducing 3–5 relay round-trips to 1.
- Added `SocialEventService::fetchArticleSocial()` — fetches all article social context (reactions, comments, labels, zap requests, zap receipts, highlights) in a single relay REQ filtered by article coordinate, reducing 2 relay round-trips to 1.
- Consolidated `FetchAuthorContentHandler` to send a single combined REQ for all content types instead of one per type. Events are routed to the correct processing logic via `AuthorContentType::fromKind()`. Reduces 3–6 relay round-trips to 1 per author content fetch.
- Expanded strfry router `user_data` stream to ingest all user context kinds (0, 3, 10000, 10001, 10002, 10003, 10015, 10020, 10063) from multiple relay sources, enabling local-first DB lookups for user profile data.
- Added `FollowsRelayPoolService` — builds a per-user consolidated relay pool from all followed authors' declared write relays (kind 10002). The pool is cached in Redis with a 30-day TTL, keyed to the kind 3 event ID, and only rebuilds when the follows list actually changes. Pool is warmed asynchronously after login sync via `WarmFollowsRelayPoolMessage`. Reduces follows-feed relay connections from N (one per followed author) to the top 25 health-ranked relays from the consolidated pool.


## v0.0.16
Feeds, walls, playlists, galleries, boards, whatever you want to call them.

- Fixed curation media-sync Mercure updates never reaching the browser: `FetchMissingCurationMediaMessage` was not routed to any Messenger transport, so it was handled synchronously during the HTTP request — the Mercure update was published before the page loaded and the EventSource connected. Now routed to the `async` transport so the worker processes it after the browser subscribes.
- Switched curation media-sync updates from polling the `/api/curation/{id}/media-sync-status` endpoint to Mercure SSE: the frontend now subscribes to the Mercure topic and reloads instantly when the handler publishes persisted events, instead of polling every 2 seconds.
- Fixed curation boards (kinds 30005/30006) not syncing coordinate-referenced media (`a` tags): boards that reference items by coordinate (`kind:pubkey:identifier`) never dispatched a relay fetch — only `e`-tag (event ID) references were synced. Now both `a`-tag coordinates and `e`-tag IDs are fetched from relays via `getEventsByCoordinates()` / `getEventsByIds()`. The sync-status endpoint also checks both tag types, coordinate lookups use the indexed `findByNaddr()` instead of a full-table scan, and video playlist placeholders show the same sync progress UI as picture grids.
- Split the monolithic worker into four dedicated Docker services: `worker` (Messenger consumers for async + async_low_priority queues), `worker-relay` (local strfry relay subscriptions for articles, media, magazines via `app:run-relay-workers`), `worker-profiles` (profile refresh daemon + async_profiles consumer via `app:run-profile-workers`), and `relay-gateway` (persistent external relay connections). Each runs in its own container with independent resource allocation, restarts, and scaling. Three Messenger transport lanes — `async`, `async_low_priority`, `async_profiles` — each have their own Redis stream so heavy work on one lane cannot starve another.
- Events received by the relay gateway are now forwarded to the local strfry relay for storage. When a query correlation completes, the gateway publishes all received events to strfry via the existing persistent WebSocket connection. The worker-relay subscription workers (articles, media, magazines) pick up relevant kinds through their existing subscriptions and persist them to the database. Other kinds are stored in strfry and queryable on subsequent page loads via the local relay. This replaces the previous approach of dispatching `PersistGatewayEventsMessage` through Messenger, avoiding the async queue bottleneck and eliminating the need for EntityManager in the long-running gateway process.
- Added info-level logging to the relay gateway for query requests (filter summary, target relays), REQ sends, EVENT receipts (kind, event ID, author, running count per subscription), EOSE completions (events received per relay), and final query results (total events, errors). Upgraded timeout logs from debug to warning. Previously only connection open/close and maintenance were logged — relay traffic was invisible at `-vv`.
- Made system-configured relay connections (content, profile, project, local) persistent in the relay gateway: they are never idle-closed and are auto-reconnected when they drop. Other shared connections remain on-demand with an increased idle timeout (15 min, up from 5 min). User connection idle timeout increased to 2 hours (up from 30 min). Persistent connections send keepalive pings every 45 seconds to prevent proxies from dropping the socket.
- Improved curation media sync (kind 30006 boards): missing media events are now fetched using the board author's declared relay list (kind 10002 write relays) in addition to e-tag relay hints and the local relay. Placeholder cards show "Syncing…" with relay attempt details (which relays were tried and when) instead of a bare "Not synced" with the full event ID. The sync-status API (`/api/curation/{id}/media-sync-status`) now returns `fetchAttempt` metadata and `missingIds`.
- Extracted RelayGateway from the worker service into its own dedicated Docker service (`relay-gateway`), enabling independent scaling, restarts, and resource allocation for the persistent WebSocket connection pool. Activate with `docker compose --profile gateway up -d`.
- Fixed relay gateway status command showing no health data: the status command searched for `relay:health:*` keys but `RelayHealthStore` writes to `relay_health:*` (underscore). Also added a gateway heartbeat indicator, richer health table columns (last success, last event, latency), and clarified that empty response streams are normal (transient, 60s TTL).
- Updated visitor analytics to exclude `/api/*` utility routes from generic visit counts while still recording API usage for endpoint analytics, and added referrer traffic summaries, top-referrer listings, and referrer details in recent visits.
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
