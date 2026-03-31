# CHANGELOG

## v0.0.26
Loading, loading, loading.

- [Bug] Restored inline math `$…$` rendering broken by the v0.0.25 currency fix. The `Converter` now normalizes `$…$` to `\(…\)` at HTML-generation time (protecting code blocks and `$$…$$`), using the TeX whitespace rule to distinguish math from currency. No client-side DOM walking needed — KaTeX in the browser keeps only the safe `\(…\)`, `$$…$$`, and `\[…\]` delimiters. Added `--math-only` flag to `articles:process-html` to reprocess only articles whose content contains `$`, avoiding a full reprocess of the entire database.
- Removed hard relay limit (previously `$limit = 5`) from `getRelaysForUser()`, `getRelaysForFetching()`, and `getRelaysForPublishing()` in `UserRelayListService`. All available relays are now returned, prioritizing the local relay (which ingests from multiple upstreams) and the user's own NIP-65 relays, followed by registry defaults. Callers no longer risk silently losing user relays or registry fallbacks to truncation.
- Fixed `isValidRelay()` edge cases in `UserRelayListService`: replaced naive `str_contains('localhost')` check with proper hostname parsing via `parse_url()`, added `filter_var(FILTER_VALIDATE_URL)` structural validation, and normalized URLs via `RelayUrlNormalizer` before checking. A URL like `wss://relay.localhost.com` is no longer incorrectly rejected.
- Introduced `RelayUrlNormalizer` utility (`src/Util/RelayUrlNormalizer.php`) — a single canonical normalize/equals for relay URLs, replacing 7+ ad-hoc normalization implementations scattered across `RelayRegistry`, `RelayHealthStore`, `NostrRelayPool`, `GatewayConnection`, `RelayAdminService`, `UpdateRelayListHandler`, and `RelayGatewayCommand`. All consumers now agree on lowercase + trim + strip-trailing-slash.
- [Performance] Replaced `KEYS relay_health:*` with `SCAN` iterator in `RelayHealthStore::getAllKnownRelayUrls()` to avoid blocking Redis when the key space is large (used by admin gateway dashboard).
- Improved relay health score algorithm with a recency bonus: relays that succeeded recently now get a score boost that decays over 24 hours, so relays that have recovered from a failure burst are no longer penalized indefinitely.
- Added `declare(strict_types=1)` to `NostrRelayPool.php` for consistency with all other files in the relay subsystem.
- Added unit tests for core relay infrastructure: `RelayUrlNormalizerTest` (14 tests), `RelayRegistryTest` (25 tests), `RelayHealthStoreTest` (14 tests), `GatewayConnectionTest` (15 tests) — covering URL normalization, purpose-based relay resolution, LOCAL/PROJECT deduplication, health scoring, muting, and gateway connection key building.
- Removed redundant `$nostrDefaultRelay` constructor parameter from `RelayAdminService`. The service now uses `RelayRegistry::getLocalRelay()` via a private `getLocalRelayUrl()` helper, with a `DEFAULT_LOCAL_RELAY` constant as fallback. Eliminates duplication between the injected parameter and the already-available registry.
- Removed hardcoded `wss://relay.decentnewsroom.com` from the settings relay controller. The project relay URL is now passed from the backend (`RelayRegistry::getPublicUrl()`) via a Stimulus `homeRelay` value, keeping the JS in sync with `relay_registry.project_relays` in `services.yaml`.
- [Performance] Extended `RelayHealthStore` TTL from 24 hours to 7 days for configured relays (project, profile, local). Ad-hoc user relays keep the 24-hour window. Prevents silent health history loss during low-activity weekends for relays that are always refreshed by workers and cron jobs.
- [Bug] Fixed kind 1 nevent lookups almost always failing. Three issues: (1) `getEventById()` prepended the local relay and capped at 3 relays, displacing the actual hint relays where the event lives — now hint relays are tried first with a cap of 5. (2) The nevent author pubkey (available in the NIP-19 encoding) was ignored — now the author's relay list (NIP-65 kind 10002) is resolved and merged into the search, which is critical for short notes that only exist on the author's personal relays. (3) The async fallback handler had the same limitations — it now also enriches the relay list with author relays before querying.
- Added Featured Articles page (`/featured-articles`): shows the latest articles from users with the Featured Writer role. The "Writers" heading link in the global FeaturedWriters sidebar now leads to this page instead of the forum.
- Added Follow Pack view page (`/follow-pack/{pubkey}/{dtag}`): a magazine-style page for any follow pack. Shows a cover image (if the event has an `image` tag), pack title, curator attribution, and the latest articles from the pack's members. The aside lists all members with the FeaturedWriters style. The "Recommended Writers" heading on profile pages now links to this view.
- Added Follow Pack setup feature: users can create and manage a follow pack (kind 39089) from a dedicated `/settings/follow-pack` page. The profile sidebar (right aside) shows a compact list of recommended writers when a follow pack is set, with a link to the setup page. For owners without a pack, an info bubble invites them to set one up. The setup form includes user search (reusing `/api/users/search`) with kind 3 follows prioritized as suggestions, existing pack management, and pack selection. The settings Events tab also links to the dedicated page. Visitors see the recommended writers list in the profile aside when the author has a follow pack.
- [Bug] Fixed CardPlaceholder fetch failing for e-tag references in 30004 curation boards. Items referenced by event ID (e-tag) instead of coordinate (a-tag) had no coordinate, pubkey, or slug — the fetch API only accepted coordinate-based lookups and returned 400 "Missing required parameters". The `/api/fetch-article` endpoint now supports fetching by event ID via `NostrClient::getEventsByIds()`, and uses `GenericEventProjector` for non-article event kinds instead of only `ArticleEventProjector`.
- Added support for addressable video event kinds 34235 (normal video) and 34236 (short video) across media ingestion, relay subscriptions, database queries, caching, templates, and JS controllers. These NIP-71 addressable kinds are now fetched and displayed alongside ephemeral kinds 21 and 22.
- Improved recommended writers sidebar on profile pages: member profiles are now batch-resolved via `getMultipleMetadata` (instead of one-by-one), only members with locally available profiles are shown, and the selection is shuffled on each page load so the sidebar shows a varied set of writers.
- Follow pack profile sync: when a user sets or selects a recommended writers follow pack, the profiles of listed npubs are now automatically synced in the background via `BatchUpdateProfileProjectionMessage`, ensuring member metadata is available for the sidebar display.


## v0.0.25

- [Performance] Ensured article HTML is pre-generated at ingestion time across all ingestion paths, not just the relay subscription worker. Previously only `ArticleEventProjector` (strfry subscription worker) converted markdown to HTML before persisting; three other paths — `ArticleFetchService` (fetch-by-pubkey, fetch-latest, ingest-range, naddr), `FetchAuthorContentHandler` (async author content fetch), and `EditorController` (user publish) — left `processed_html` NULL, causing on-the-fly conversion on every article page load. All four paths now call `Converter::convertToHTML()` before `persist()`, with graceful fallback if conversion fails.
- [Bug] Fixed KaTeX rendering dollar-sign currency values (e.g. `$50,000`) as math expressions in UnfoldBundle pages and the main app. The UnfoldBundle templates had no heuristic check before calling `renderMathInElement`, so any `$` in article text was treated as a math delimiter — entire paragraphs between two prices were swallowed into garbled KaTeX output. Added a shared `katex-init.js` with a `hasRealMath` heuristic that skips rendering unless genuine LaTeX syntax is detected. Also removed the ambiguous single-`$` delimiter from all render calls (both UnfoldBundle and the Stimulus controller), keeping only `$$…$$`, `\(…\)`, and `\[…\]` which don't conflict with currency.
- Added Relay List editor to the settings page: a new "Relays" tab lets users view, add, and remove relays from their NIP-65 relay list (kind 10002), set read/write markers per relay, sign the updated event via their Nostr signer, and publish it to relays. The Events tab relay card now links to the editor. Cache is invalidated on publish so changes take effect immediately.
- [Bug] Fixed RSS-imported articles not appearing on the author's profile tab. The RSS publish endpoint was missing Redis cache invalidation after persisting articles — stale cached data (from a previous profile visit) would mask the newly imported articles. Added `invalidateUserArticles()` and `invalidateProfileTabs()` calls after projection, plus async `RevalidateProfileCacheMessage` dispatch so the profile rebuilds immediately. Also fixed relay selection to use the event author's pubkey instead of the admin session user's.
- [Bug] Fixed `ChatUserProvider` service being pruned by Symfony's `RemoveUnusedDefinitionsPass`, causing `cache:clear` to fail with "dependency on a non-existent service". Same root cause as the earlier `ChatRequestMatcher` fix — the security firewall's `provider: chat_user_provider` reference doesn't prevent removal. Added explicit service definition in `services.yaml`.
- [Bug] Fixed ChatBundle `ChatRequestMatcher` service not being registered in the container. The auto-discovered service was pruned by Symfony's `RemoveUnusedDefinitionsPass` because the security firewall's `request_matcher` reference didn't prevent removal. Added explicit service definition in `services.yaml`. Also excluded `ChatBundle/Entity/` from service auto-discovery (entities should not be registered as services).
- [Performance] Fixed nostr-tools SimplePool being loaded on every page. Two root causes: (1) the `utility--signer-modal` Stimulus controller was declared on the shared `UserMenu` wrapper div, causing the browser to eagerly load the controller (and its heavy `nostr-tools` imports including SimplePool, crypto, and WebSocket code) on every page — even for logged-in users who never see the signer modal. Moved the controller declaration to the non-logged-in branch only. (2) `signer_manager.js` had top-level static imports of `nostr-tools`, `nostr-tools/nip46`, and `nostr-tools/utils`, which meant any controller importing even lightweight helpers (`clearRemoteSignerSession`, `getRemoteSignerSession`) would pull in the full bundle — including `logout_controller.js` which is on every page for logged-in users. Converted all nostr-tools imports in `signer_manager.js` and `signer_modal_controller.js` to dynamic `import()` calls so the crypto/WebSocket modules are only fetched when actually needed (remote signer reconnection or modal interaction). Also marked `NostrRelayPool`, `NostrClient`, and `NostrRequestExecutor` as lazy Symfony services so their constructors (relay list building, logging, dependency chains) don't run on pages that never query relays.
- [Bug] Fixed relay subscription workers replaying all stored events on every startup/reconnection. The `subscribeLocal()` filter had no `since` parameter, so the local strfry relay would send the entire event history for the subscribed kinds — each event was looked up in the database only to be skipped as a duplicate. Workers now query the latest `created_at` timestamp from the database for their event kinds and pass it as a `since` filter, so only genuinely new events are received. Also reduced the per-event log from INFO to DEBUG for pre-EOSE (historical replay) events to cut log noise.
- [Bug] Optimized naddr relay lookup: when relay hints are present, the synchronous controller query now targets only the hint relays (+ local) with a 15-second gateway timeout and no fallback to default relays. Previously, a failed hint query (8s timeout) was immediately followed by a second query to all default relays (another 8s), wasting 16+ seconds before falling back to async. The async handler retains the broader search strategy as it is the fallback path.
- Removed `NostrRelayPool` dependency from `NostrClient` and `UserProfileService`. Moved `ensureLocalRelayInList()` (pure URL logic) to `RelayRegistry`; moved `publish()` to `NostrRequestExecutor`. `RelaySetFactory::withLocalRelay()` now also uses `RelayRegistry`.
- [Bug] Fixed infinite loading spinners on naddr/nevent event lookup pages. Four root causes: (1) the timeout-reload loop in `event_fetch_controller.js` used an instance variable (`_reloaded`) that was lost on page reload, causing an infinite 30-second reload cycle — now uses `sessionStorage` to track the retry; (2) `FetchEventFromRelaysHandler` used `GenericEventProjector` which only creates `Event` entities, so after async fetch of a kind 30023 article the `Article` table was empty and the redirect to the article page showed "not found" — now also calls `ArticleEventProjector` for article events; (3) `EventController` redirected articles to the disambiguation route (`/article/d/{slug}`) without the author pubkey, which fails when multiple authors share a slug — now redirects to `author-article-slug` with the pubkey from the naddr; (4) consolidated all naddr resolution into `EventController` — the `/article/naddr1…` route now simply redirects to `/e/naddr1…`, eliminating duplicated relay fetch logic, inconsistent table lookups, and the loading template already reloading to `/e/…` anyway. `EventController` now checks the `Article` table as a fast path for kind 30023 naddr lookups before falling through to the `Event` table.
- [Bug] Fixed feedback form (kind 24) not working: the form was posting to `/api/nostr/publish` which had no matching controller route. Created `FeedbackApiController` at that path that validates the event signature, persists the feedback event locally via `GenericEventProjector`, and publishes it to the local strfry relay.
- Added Feedback Messages administration page (`/admin/feedback`): lists all kind 24 feedback events from the database with sender, message content, recipients, and timestamp. Linked from the admin dashboard Tools grid.
- [Bug] Fixed "Load more" button on author profile media tab doing nothing. The template referenced a non-existent `content--author-media` Stimulus controller. Rewired the template to use the existing `media--media-loader` controller with proper `data-controller`, Stimulus values (`npub`, `page`, `total`), and targets (`grid`, `button`, `status`). Updated the controller to find the `.masonry-grid` element by class when no explicit grid target is set, avoiding changes to the shared masonry partial.
- [Bug] Fixed articles tab on author profile pages showing only a subset of articles. The database query was hard-limited to 100 articles with no pagination. Raised the fetch limit to 500 and added paginated navigation (20 articles per page) using the existing Pagination component, matching the pattern used on forum/tag pages. The background cache revalidation handler was updated to match.
- [Bug] Fixed highlights page showing very few results due to compounding filters: deduplication now keeps one highlight per article per author (was one per article); the kind filter now accepts all addressable event types including publications (kind 30041), not just articles (kind 30023); the relay fetch window was widened from 30 to 90 days; and all pipeline limits (relay fetch, cron, cache, display) were raised from 50–100 to 200.
- [Bug] Fixed incomplete articles (missing slug or title) appearing as "Article Not Yet Available" placeholders in the latest articles list, discover page, and home feed. Added empty-string checks to the cache-building query, all three Redis-reading controller paths, and the CardList template (which now silently skips incomplete items instead of rendering a placeholder card).
- Added `ROLE_RSS` role: users with this role can access the RSS administration page (`/admin/rss`) to import articles from RSS feeds without requiring full admin privileges. Added an "RSS Managers" section to the admin roles page (`/admin/role`) with add/remove forms for granting this role to any npub. The role can also be assigned via CLI with `user:elevate npub1... ROLE_RSS`.
- [Bug] Fixed RSS-imported articles not appearing in author profiles, discover page, or search results. The RSS publish endpoint was only persisting events to the `event` table via `GenericEventProjector` but not to the `article` table. Added `ArticleEventProjector::projectArticleFromEvent()` call for kind 30023/30024 events so they are properly indexed, HTML-processed, and visible across the application.
- [Bug] Fixed magazine cards in the profile overview tab missing cover images. The template was not rendering the image block. Also added the `image` field to the fallback Event-to-array conversion in both the controller and the background cache revalidation handler so cover images are available regardless of data source.
- [Bug] Fixed curation collection items (kind 30005/30006) never being found when referenced by coordinate (`a` tags). Relay hints embedded in `a` tags were silently ignored — only `e`-tag relay hints were collected. The async `getEventsByCoordinates()` fetch also had no way to receive external relay URLs, so it could only query the local strfry relay (which doesn't have the items) and the referenced author's declared relays (which are often unknown for third-party authors). Now `a`-tag relay hints are collected alongside `e`-tag hints, passed through `FetchMissingCurationMediaMessage`, and merged into every coordinate fetch request.
- [Bug] Fixed kind 30004 curation sets only displaying article-kind items. Collections can reference events of any kind via `a`/`e` tags, but the controller only looked up articles in the Article table — non-article items (media, playlists, notes, etc.) were silently dropped, leading to "X items" in the badge but zero visible content. Now all referenced events are resolved from the Event table regardless of kind, with Article table enrichment for longform articles. Non-article items render as generic cards with title, author, kind badge, and a link to the event page. Missing items are dispatched for async relay fetch with the same Mercure-based sync UI used by media collections.



## v0.0.24
Styles, RSS import, and admin tools.

- Added admin-only zap split field in the magazine setup wizard: admins can specify an npub that receives 100% of zaps on the magazine and any newly created categories within it. The zap tag is included in the signed Nostr events and preserved on edit.
- Added "Copy npub" button to author profile pages, allowing users to copy the author's npub to the clipboard with one click.
- [Bug] Fixed RSS feed `media:content` images not being detected as cover images. Root cause: feeds like Ghost declare both `xmlns:content` (for `content:encoded`) and `xmlns:media` (for `media:content`). SimpleXML's `$element->children($mediaUri)->content` property access confused the local name "content" with the `content` namespace prefix, silently returning nothing. Switched to XPath (`media:content`) which resolves namespaced elements unambiguously. Also moved namespace resolution to the document root via `getDocNamespaces(true)`, added `media:thumbnail` / `<enclosure type="image/*">` fallbacks, and extended image extraction to Atom feeds.
- [Bug] Fixed RSS import leaking CSS, JavaScript, and embed markup into article Markdown. Ghost CMS feeds include `<style>`, `<script>`, HTML comments (`<!--kg-card-->`), and custom elements (`<lightning-widget>`) in `content:encoded`. The `strip_tags()` call removed the tags but left their text content (e.g. `span.small { font-size: smaller; }`). Added pre-processing to remove `<style>`, `<script>`, `<noscript>`, HTML comments, embed widgets, and non-content wrapper elements before Markdown conversion.
- [Bug] Fixed RSS import duplicating the cover image in the article body. Ghost feeds include the cover image as an `<img>` inside `content:encoded` in addition to the `media:content` element. The image was appearing both as the `['image', url]` tag and as a `![alt](url)` at the top of the Markdown content. The cover image `<img>` is now stripped from the HTML before Markdown conversion.
- Implemented RSS administration page (`/admin/rss`): fetch any RSS or Atom feed URL, preview articles with duplicate detection (already-imported items are dimmed), select/deselect articles, then review and batch-sign them as Nostr kind 30023 longform events via the user's Nostr signer. Signed events are persisted locally and published to the user's write relays. Includes per-article progress tracking and relay result feedback.
- Added Mercure administration page (`/admin/mercure`): shows hub configuration, connectivity test, BoltDB transport status, active SSE subscriptions grouped by topic, a publish-test tool with live SSE listener, and a registry of all known topic patterns used in the application.
- [Bug] Fixed Mercure SSE connections cycling every 3–4 seconds across the entire app (comments, author content, curation, chat). Two causes: (1) `encode gzip` in the Caddyfile wrapped Mercure SSE responses, and the gzip buffering broke streaming — SSE data was flushed as completed HTTP responses instead of held open; (2) no `heartbeat_interval` was configured, so idle connections were closed by Docker/proxy networking before the default 40 s heartbeat fired. Fix: exclude `/.well-known/mercure*` from gzip encoding, add `heartbeat_interval 15s`, remove the overly aggressive `write_timeout 10s` and `dispatch_timeout 5s`.
- Improved naddr/nevent search bar handling: pasting a Nostr address (naddr1…, nevent1…) into any search bar now decodes and validates the entity client-side, shows inline feedback (including relay count from the address), and redirects to the event page. When the event is not in the database but relay hints are embedded in the address, those relays are queried synchronously in the controller (no async worker delay) since they have a high hit rate. Only when hint relays fail or are absent does the request fall back to an async broader relay search. The loading page now shows accurate phased progress: "not in our database, querying relays" initially, with a "still searching" notice after 6 seconds.
- [Bug] Fixed latest articles showing drafts (kind 30024). The cache command, database fallback, and Elasticsearch queries were missing a kind filter, so draft articles appeared alongside published ones in the latest articles feed, discover page, and follows feed.
- Highlights from external sources (articles not in the local database) are now shown on the highlights page. Previously, highlights referencing articles only available on remote relays were silently dropped. Now a minimal article view is built from the highlight's article coordinate, and the article author's profile is fetched from the metadata cache for display.
- [Bug] Fixed pagination not appearing on forum topic pages. The Elasticsearch `findByTopics` query was missing `collapse` on the `slug` field, so the same article matched by multiple tags consumed multiple slots in the 200-result limit. After controller-side deduplication, too few unique articles remained to trigger the >1-page threshold. Added slug collapse (matching `findByTag` behaviour) and `DISTINCT`/`ORDER BY`/`LIMIT` to the database implementation.


## v0.0.23
Styles, magazines, and bug fixes.

- Magazine wizard now skips step 5 (subdomain) when editing a magazine for which the user already has an active subdomain subscription. The wizard progress indicator removes the subdomain step entirely and the review step redirects straight to done.
- [Bug] Fixed magazine index signing flow aborting entirely when the user rejects signing any single category or the magazine event. Rejected or failed signings are now skipped gracefully, and the signer advances to the next item in the list. A summary is shown at the end indicating which events were published and which were skipped.
- Added featured Unfold sites section: Unfold-hosted magazine subdomains are now shown as premium content at the top of the discover page and the authenticated home feed. Each card displays the magazine title, summary, and cover image resolved from the local event database, with a link to the hosted subdomain. Results are cached for 15 minutes.
- [Bug] Fixed Stimulus controllers from `ui/` and `utility/` folders not loading (article actions, login, sidebar, etc. all broken). The `asset-map:compile` command added to the docker-entrypoint was generating a truncated `controllers.js` that silently dropped ~30 controllers. Pre-compiled assets in `public/assets/` override Symfony's dynamic asset serving, so the broken file was served to every browser. Fix: `asset-map:compile` now only runs when `APP_ENV=prod`; in dev mode, any stale pre-compiled assets are automatically removed so the stimulus-bundle compiler generates the full controller map dynamically.
- [Bug] Fixed `cache:clear` I/O error on startup (`Failed to open stream: Input/output error` for `UnfoldBundle.php`). Root cause: Symfony's kernel boot loads every bundle class from `bundles.php` via `include()`, and the Docker bind mount (Windows → Linux) produces persistent I/O errors on the `UnfoldBundle.php` file. Fix: de-bundled both `UnfoldBundle` and `ChatBundle` — removed them from `bundles.php`, moved their DependencyInjection parameters and service scans into the main `config/services.yaml`, replaced `@UnfoldBundle`/`@ChatBundle` route notation with direct file paths, added the `@Chat` Twig namespace to `twig.yaml`, and converted `config/packages/chat.yaml` from bundle config tree to plain parameters. 
- [Bug] Fixed Mercure SSE subscriptions not working in production.
- Added polling fallback for async event fetching: the Stimulus controller now polls a new `/api/event-fetch-status/{lookupKey}` endpoint alongside Mercure SSE.
- Replaced synchronous relay fetching on the `/e/naddr1…` (and `nevent`/`note`) route with an async Messenger job. The page now instantly renders a loading placeholder with a spinner, subscribes to a Mercure topic for the result, and automatically reloads when the event is found — or shows a "not found on relays" state with a retry button after timeout. This eliminates the `Maximum execution time of 15 seconds exceeded` error and stops slow relay lookups from blocking FrankenPHP workers.
- Added magazine preview section to the home page: shows the Newsroom Magazine title with a horizontal category slider, each category displaying its latest article card. Includes a Stimulus-driven slider with arrow navigation, touch/swipe support, and a link to the external Unfold site.
- Added subdomain analytics to the admin visitor analytics page.
- CSS variable audit: extended `theme.css` with semantic status colors (`--color-success`, `--color-error`, `--color-warning`, `--color-info`), nostr accent (`--color-nostr`), shadow tokens (`--shadow-sm/md/lg`), z-index scale, transition tokens, layout constants (`--header-height`, `--sidebar-width`), and font-size scale. Replaced ~200 hardcoded color/spacing/border-radius values across 25+ files with CSS variables. Enforced "no rounded edges" project rule (all `border-radius` set to 0). All three themes (default, light, space) now properly inherit all tokens.
- [Bug] Fixed graph layer lag: `ArticleEventProjector`, `ArticleFetchService`, `MediaEventProjector`, `FetchAuthorContentHandler::saveArticle()`, and `EditorController::publishNostrEvent()` were persisting events without calling `EventIngestionListener`, causing `current_record` and `parsed_reference` tables to drift until the nightly `dn:graph:audit --fix` repaired them. All ingestion paths now update the graph layer inline, eliminating the hours-long lag and reducing audit repair volume to near zero.
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
