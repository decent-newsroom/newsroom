# Plan: Collect All Relevant Nostr Event Kinds at Once

> **Status:** Planned  
> **Date:** 2025-03-19  
> **Goal:** Reduce relay round-trips by fetching related event kinds in combined REQ messages wherever possible.

---

## 1. Current State Analysis

### What Already Works Well

| Pattern | Location | Kinds Fetched Together |
|---------|----------|----------------------|
| Login batch sync | `SyncUserEventsHandler` | 16 kinds in one REQ (0, 3, 7, 1111, 10002, 10003, 10015, 10020, 30003, 30004, 30005, 30006, 30023, 30024, 30040, 30015) |
| Comments + zaps | `SocialEventService::getComments()` | 1111 + 9735 in one filter |
| Bookmarks | `UserProfileService::getBookmarks()` | 10003, 30003, 30004, 30005, 30006 in one REQ |
| strfry ingest stream | `docker/strfry/router.conf` | 30004, 30005, 30006, 30040, 30023, 30024, 1111, 9735, 9802, 10002, 0, 5 |

### What Is Currently Fetched Separately (Opportunity)

| Method | Kinds | Where Called |
|--------|-------|-------------|
| `UserProfileService::getMetadata()` | 0 | Profile pages, article author display |
| `UserProfileService::getFollows()` | 3 | Follows tab, interests tab, media discovery |
| `UserProfileService::getInterests()` | 10015 | Interests tab, forum/topics |
| `UserProfileService::getMediaFollows()` | 10020 | Media discovery page |
| `UserRelayListService::fromNetwork()` | 10002 | Relay resolution fallback |
| `SocialEventService::getZaps()` | 9735 | Standalone zap fetch (separate from comments which already include 9735) |
| `SocialEventService::getHighlightsForArticle()` | 9802 | Article page, draft page |
| `FetchAuthorContentHandler` per content type | 30023, 30024, 20/21/22, 9802, etc. | Separate REQ per `AuthorContentType` in a loop |

### Key Observations

1. **`UserProfileService`** methods (`getMetadata`, `getFollows`, `getInterests`, `getMediaFollows`) each query relays independently for a single kind. When a page needs the user's follows *and* interests (e.g., building a personalized feed), this generates 2+ separate relay round-trips.

2. **`FetchAuthorContentHandler`** iterates `foreach ($contentTypes as $contentType)` and opens a **separate relay subscription per content type** (line 74). Since each `AuthorContentType` maps to different kinds, a profile page requesting articles + media + highlights sends 3–6 separate REQs to the same relays.

3. **Article page** (`ArticleController::authorArticle`) fetches author metadata via `RedisCacheService`, then highlights via `HighlightService` — but comments are loaded lazily by the `<twig:Organisms:Comments>` live component. The highlights and comments could share a single REQ (kinds 1111 + 9735 + 9802 filtered by `#a` tag).

4. **strfry `user_data` stream** only pulls kinds 0 and 10002. Adding kinds 3, 10015, 10003, and 10020 would make follow/interest/bookmark data available locally, removing the need for external relay calls when users browse other profiles.

---

## 2. Kind Bundles

Define named groups of related kinds that should be fetched together. These become the building blocks for combined REQ filters.

### Bundle A: User Context

Everything needed to understand a user's identity, social graph, and preferences.

```
USER_CONTEXT = [
    0,      // metadata (NIP-01)
    3,      // follows (NIP-02)
    5,      // deletion requests (NIP-09)
    10000,  // mute list (NIP-51)
    10001,  // pin list (NIP-51)
    10002,  // relay list (NIP-65)
    10003,  // bookmarks (NIP-51)
    10015,  // interests (NIP-51)
    10020,  // media follows (NIP-68)
    10063,  // Blossom server list (NIP-B7)
    30015,  // interest sets (NIP-51)
]
```

**Use case:** On any DB miss for *any* of these kinds for a given pubkey, fetch *all* of them in one REQ. All are replaceable events (only latest matters), so the filter can use multiple sub-filters with `limit: 1` each, or one combined filter scoped to `authors: [pubkey]`.

### Bundle B: Article Social Context

All social interactions referencing a specific article coordinate.

```
ARTICLE_SOCIAL = [
    7,      // reactions (NIP-25)
    1111,   // comments (NIP-22)
    1985,   // labels (NIP-32)
    9734,   // zap requests (NIP-57)
    9735,   // zap receipts (NIP-57)
    9802,   // highlights (NIP-84)
]
```

**Use case:** When loading an article page, fetch all kinds in a single REQ filtered by `#A: [coordinate]` or `#a: [coordinate]`. Replace the current pattern of separate calls for comments+zaps and highlights.

### Bundle C: Author Content

All publishable content types for an author's profile page.

```
AUTHOR_CONTENT = [
    20,     // images (NIP-68)
    21,     // video (NIP-71)
    22,     // short video (NIP-71)
    1111,   // comments (NIP-22)
    9802,   // highlights
    10003,  // bookmarks
    10015,  // interests
    30003,  // bookmark sets
    30004,  // curation sets — articles
    30005,  // curation sets — videos
    30006,  // curation sets — pictures
    30015,  // interest sets (NIP-51)
    30023,  // long-form articles (NIP-23)
    30024,  // long-form drafts (NIP-23, owner only)
    34139,  // playlists
]
```

**Use case:** `FetchAuthorContentHandler` should combine all these into a single REQ instead of one per `AuthorContentType`.

---

## 3. Implementation Phases

### Phase 0: Infrastructure — `KindBundles` Enum + Multi-Filter Support

**Files to create/modify:**

- **Create** `src/Enum/KindBundles.php` — a class (not enum) with `public const` arrays for each bundle and helper methods:

```php
final class KindBundles
{
    public const USER_CONTEXT = [0, 3, 5, 10000, 10001, 10002, 10003, 10015, 10020, 10063, 30015];
    public const ARTICLE_SOCIAL = [7, 1111, 1985, 9734, 9735, 9802];
    public const AUTHOR_CONTENT = [20, 21, 22, 1111, 9802, 10003, 10015, 30003, 30004, 30005, 30006, 30015, 30023, 30024, 34139];

    /**
     * Group events by kind from a flat list.
     * @return array<int, object[]> kind => events
     */
    public static function groupByKind(array $events): array { ... }
}
```

- **Verify** `NostrRequestExecutor::fetch()` supports multiple `kinds` — it already does, so no change needed there.

**Effort:** Small. No behavior change.

---

### Phase 1: Combined User Context Fetch

**Problem:** `getMetadata()`, `getFollows()`, `getInterests()`, `getMediaFollows()` each make independent relay calls on DB miss. Mute list, pin list, Blossom servers, interest sets, and deletion requests are not fetched on-demand at all.

**Solution:** Add `fetchUserContext(string $pubkey)` to `UserProfileService` that fetches all `USER_CONTEXT` kinds (11 kinds) in one REQ and persists each result. Modify the existing methods to call this combined fetch on relay fallback instead of fetching their single kind.

**Files to modify:**

1. **`src/Service/Nostr/UserProfileService.php`**
   - Add `public function fetchUserContext(string $pubkey, ?array $relays = null): void`
     - Sends one REQ with `kinds: KindBundles::USER_CONTEXT, authors: [$pubkey]`
     - Groups response by kind via `KindBundles::groupByKind()`
     - For each kind, takes the latest event (by `created_at`) and persists via `persistEvent()`
   - Modify `getFollows()`, `getInterests()`, `getMediaFollows()` relay-fallback paths:
     - Instead of fetching their single kind, call `fetchUserContext()` then re-query DB
     - This means: DB miss → `fetchUserContext()` → DB re-read → return
   - `getMetadata()` is trickier because it returns the event directly, not from DB. Either:
     - (a) Also persist metadata to DB and read back, or
     - (b) Have `fetchUserContext()` return the grouped events so the caller can pick the one it needs
     - **Recommendation:** Option (b) — return a keyed array `[kind => latestEvent]`

2. **`src/MessageHandler/SyncUserEventsHandler.php`** — no immediate change needed; it already fetches broadly. But document that `fetchUserContext()` is the synchronous on-demand equivalent for cold misses.

**Expected impact:** A profile page that triggers `getFollows()` + `getInterests()` goes from 2 relay round-trips to 1 (plus DB read).

**Risks:** 
- If a relay returns a huge number of bookmarks/curation events, the combined response could be large. Mitigate with `limit` per filter.
- Some relays may not support multi-kind filters well. The existing retry logic in `NostrRequestExecutor` should handle this.

---

### Phase 2: Combined Article Social Fetch

**Problem:** Article page loads comments (1111+9735) and highlights (9802) in separate relay calls.

**Solution:** Add `fetchArticleSocial(string $coordinate)` to `SocialEventService` that fetches all `ARTICLE_SOCIAL` kinds (7, 1111, 1985, 9734, 9735, 9802) in one REQ filtered by the `#a` or `#A` tag.

**Files to modify:**

1. **`src/Service/Nostr/SocialEventService.php`**
   - Add `public function fetchArticleSocial(string $coordinate): array`
     - Sends one REQ with `kinds: [7, 1111, 1985, 9734, 9735, 9802]`, tag filter `#A: [$coordinate]`
     - Returns `['reactions' => [...], 'comments' => [...], 'labels' => [...], 'zap_requests' => [...], 'zaps' => [...], 'highlights' => [...]]`
   - Deprecate/simplify `getHighlightsForArticle()` to use `fetchArticleSocial()` internally or redirect callers

2. **`src/Service/HighlightService.php`**
   - Update `getHighlightsForArticle()` to optionally accept pre-fetched highlights from the combined call, or call the new combined method

3. **`src/Twig/Components/Organisms/Comments.php`** (the live component)
   - If it currently calls `getComments()` on mount, update to call `fetchArticleSocial()` and pass highlights to the template context (or cache them for the article controller to read)

4. **Alternative approach — controller-level coordination:**
   - `ArticleController::authorArticle()` already fetches highlights (when enabled). It could call `fetchArticleSocial()` once and pass both highlights and a pre-warmed comment cache to the template. The Comments live component would then read from cache/DB instead of hitting relays.

**Expected impact:** Article page goes from 2 relay calls (comments+zaps, highlights) to 1.

---

### Phase 3: Consolidated Author Content Fetch

**Problem:** `FetchAuthorContentHandler` iterates content types and sends one REQ per type.

**Solution:** Combine all kinds into a single REQ, then route received events to the correct processing logic by checking `$event->kind`.

**Files to modify:**

1. **`src/MessageHandler/FetchAuthorContentHandler.php`**
   - Replace the `foreach ($contentTypes as $contentType)` loop (line 74) with:
     1. Collect all kinds from all content types: `$allKinds = $message->getKinds()`
     2. Send one REQ with all kinds + `authors: [$pubkey]`
     3. For each received event, determine which `AuthorContentType` it belongs to via a reverse lookup
     4. Route to the appropriate processing (`processEvents()`)
   - Add a helper `AuthorContentType::fromKind(int $kind): ?self` for the reverse mapping

2. **`src/Enum/AuthorContentType.php`**
   - Add `public static function fromKind(int $kind): ?self` that maps a kind integer back to the content type

**Expected impact:** A profile page dispatch sends 1 REQ instead of 3–6. Processing logic stays the same; only the fetch layer changes.

**Risks:**
- Different content types currently have different `limit` values (articles: 100, media: 100, etc.). A combined filter would need a higher combined limit. Use `limit: 500` or remove limit for async handler.
- The `processEvents()` dispatch uses `switch ($contentType)` — this still works, just the routing is inverted (event→type instead of type→fetch).

---

### Phase 4: Local Relay First for Followed Authors

**Problem:** When articles from followed authors aren't in the DB, the system fetches from external relays. But strfry already mirrors articles (kind 30023) from major relays.

**Solution:** For follows/interests content, query the local strfry relay first before going to external relays.

**Files to modify:**

1. **`src/Service/Nostr/ArticleFetchService.php`**
   - In `fetchForPubkey()`, when the caller is building a follows feed:
     - Query local relay only (via `$this->nostrDefaultRelay`)
     - If results found, return them without hitting external relays
     - Only fall back to external relays if local relay returns nothing
   - Add a `bool $localOnly = false` parameter or create a separate `fetchFromLocal()` method

2. **`src/Controller/Reader/HomeFeedController.php`**
   - The follows tab already queries the DB. If DB has results, no relay call is made.
   - The issue is when articles are *missing* from DB. In that case, dispatching `FetchAuthorArticlesMessage` could prefer local relay.
   - Add a note that the local relay should be the first-choice source for articles from followed authors.

**Expected impact:** Reduces external relay traffic for common follow-feed scenarios. Most articles from followed authors are already in strfry via the `ingest` stream.

---

### Phase 5: Expand strfry Router to Cache User Context Kinds

**Problem:** strfry `user_data` stream only syncs kinds 0 and 10002. Follows (3), mute lists (10000), pin lists (10001), interests (10015), bookmarks (10003), media follows (10020), and Blossom servers (10063) are not cached locally.

**Solution:** Expand the strfry router configuration to ingest these additional kinds.

**Files to modify:**

1. **`docker/strfry/router.conf`**
   - Update the `user_data` stream filter to include all user context kinds:
     ```
     filter = {"kinds":[0,3,10000,10001,10002,10003,10015,10020,10063]}
     ```
   - Consider adding more relay sources (e.g., `wss://nos.lol`, `wss://relay.damus.io`) to the `user_data` stream

**Expected impact:** User context data becomes available locally, making Phase 1's DB-first path hit more often without needing to call external relays.

**Trade-offs:**
- Increased disk usage and bandwidth on the strfry instance (kind 3 events can be large — hundreds of p-tags per user)
- Consider filtering by known active user pubkeys via a strfry write-policy plugin to limit ingestion to relevant users only
- Monitor strfry disk usage after deployment

---

## 4. Fetch Map: Before & After

### User Profile Page

| Data | Before | After |
|------|--------|-------|
| Metadata (0) | 1 REQ | — |
| Follows (3) | 1 REQ | — |
| Deletion requests (5) | not fetched | — |
| Mute list (10000) | not fetched | — |
| Pin list (10001) | not fetched | — |
| Interests (10015) | 1 REQ | — |
| Media Follows (10020) | 1 REQ | — |
| Relay List (10002) | 1 REQ (if not cached) | — |
| Blossom servers (10063) | not fetched | — |
| Interest sets (30015) | not fetched on-demand | — |
| **Combined** | — | **1 REQ** (Phase 1) |
| **Total relay round-trips** | **3–5** | **1** |

### Article Page

| Data | Before | After |
|------|--------|-------|
| Author metadata | Redis cache (0 REQs usually) | Same |
| Reactions (7) | not fetched per-article | — |
| Comments + Zaps (1111 + 9735) | 1 REQ | — |
| Zap requests (9734) | not fetched | — |
| Highlights (9802) | 1 REQ | — |
| Labels (1985) | not fetched | — |
| **Combined** | — | **1 REQ** (Phase 2) |
| **Total relay round-trips** | **2** | **1** |

### Author Content Async Fetch

| Data | Before | After |
|------|--------|-------|
| Articles (30023) | 1 REQ | — |
| Drafts (30024) | 1 REQ | — |
| Media (20, 21, 22) | 1 REQ | — |
| Comments (1111) | not fetched here | — |
| Highlights (9802) | 1 REQ | — |
| Bookmarks (10003, 30003) | 1 REQ | — |
| Interests (10015) | 1 REQ | — |
| Interest sets (30015) | not fetched here | — |
| Playlists (34139) | not fetched | — |
| **Combined** | — | **1 REQ** (Phase 3) |
| **Total relay round-trips** | **3–6** | **1** |

---

## 5. Implementation Order & Dependencies

```
Phase 0: KindBundles class (no dependencies)
    │
    ├── Phase 1: User Context combined fetch
    │       └── Phase 4: Local relay first (depends on Phase 1 patterns)
    │
    ├── Phase 2: Article Social combined fetch (independent of Phase 1)
    │
    ├── Phase 3: Author Content consolidated (independent of Phase 1/2)
    │
    └── Phase 5: strfry router expansion (independent, deploy anytime)
```

Phases 1, 2, 3 can be implemented in parallel. Phase 5 can be deployed at any time (it's a config change).

---

## 6. Technical Considerations

### Multi-Filter REQ Messages

The Nostr protocol supports arrays of filters in a single REQ:
```json
["REQ", "sub-id", {"kinds":[0], "authors":["abc"], "limit":1}, {"kinds":[3], "authors":["abc"], "limit":1}]
```

For User Context (Phase 1), using multiple filters (one per replaceable kind, each with `limit: 1`) is more efficient than one combined filter because it ensures we get exactly one event per kind. Verify that `NostrRequestExecutor` / the `swentel/nostr` library supports passing multiple filters to a single subscription.

### Relay Filter Size Limits

Some relays reject filters with too many kinds or too-large filters. The bundles are deliberately kept under 15 kinds each. If a relay returns a CLOSED or error, the fetch should fall back to individual kind requests. This could be implemented as a generic retry-with-split in `NostrRequestExecutor`.

### Cache Invalidation

When fetching multiple kinds at once, ensure that the persistence layer correctly handles "latest wins" for replaceable events (kinds 0, 3, 10000, 10001, 10002, 10003, 10015, 10020, 10063). The existing `persistEvent()` in `UserProfileService` already checks for existing events by ID, but should also handle the case where a newer event replaces an older one for the same kind+pubkey.

### Monitoring

Add logging/metrics to track:
- Number of relay round-trips per page load (before/after)
- Cache hit rates for user context data (DB hit vs relay fallback)
- strfry disk usage growth after expanding `user_data` kinds (Phase 5)

---

## 7. Additional Optimization Ideas

### 7.1 Batch Metadata Prefetch for Article Lists (N+1 Elimination)

**Problem:** Every `<twig:Molecules:UserFromNpub>` component calls `RedisCacheService::getMetadata()` individually during mount. When rendering a list of 20 articles from 15 unique authors, this generates 15 sequential Redis lookups (or worse, 15 DB queries + async dispatch on cache miss). The `CardList` component already accepts an `authorsMetadata` array but most callers pass it empty.

**Solution:** In every controller or service that builds an article list (home feed tabs, search results, tag pages, follows feed, bookmarks), collect all unique author pubkeys from the article set, call `RedisCacheService::getMultipleMetadata()` once, and pass the result map into the template as `authors_metadata`. The `Card.html.twig` template already checks `authors_metadata[pubkey]` and passes it into `UserFromNpub` — the infrastructure is there, it's just not used consistently.

**Files to audit:**
- `src/Controller/Reader/HomeFeedController.php` — all tab methods
- `src/Controller/Reader/ArticleController.php` — tag pages, latest
- `src/Controller/Search/SearchController.php` — search results
- `src/Controller/Reader/BookmarksController.php` — bookmark list
- `src/Twig/Components/Organisms/FeaturedList.php` — featured articles sidebar

**Expected impact:** Eliminates 10–20 individual Redis/DB lookups per list page. `getMultipleMetadata()` uses `$this->npubCache->getItems()` which is a single Redis MGET under the hood.

---

### 7.2 NIP-45 COUNT for Social Metrics Without Full Fetch

**Problem:** Showing reaction/comment/zap counts on article cards requires fetching all events of those kinds. For a list page with 20 articles, this would mean 20 separate REQs just for counts — prohibitively expensive.

**Solution:** Use NIP-45 `COUNT` verb (already documented in `documentation/NIP/45.md`) to get aggregate counts without transferring full events. The local strfry relay supports COUNT. For article list pages, send a batch of COUNT requests for kinds 7, 1111, 9735 filtered by `#a` tag for each visible article coordinate. Display these as badges on cards.

**Implementation sketch:**
1. Add `sendCount(array $filter): ?int` to `NostrRelayPool` — sends `["COUNT", subId, filter]` to the local relay and parses the `{"count": N}` response
2. Add `SocialEventService::getCountsForCoordinates(array $coordinates): array` — batch COUNT queries, returns `[coordinate => ['comments' => N, 'reactions' => N, 'zaps' => N]]`
3. Call from article list controllers and pass counts into card templates

**Trade-offs:** Only works against relays that support NIP-45. The local strfry relay does, so this is safe for locally-cached content. Don't rely on it for external relays.

---

### 7.3 Opportunistic Warm-Through on Render

**Problem:** When a user visits an article page, the system fetches social context (comments, highlights). But if the user then navigates to the author's profile, the author's context (follows, interests, relay list) is fetched separately. The article page already knows the author's pubkey.

**Solution:** After rendering the article page, dispatch a low-priority `FetchUserContextMessage` for the article's author pubkey in a `register_shutdown_function` (fire-and-forget after response). This warms the cache for the most likely next navigation (clicking the author name). The cost is near-zero since it runs after the response is sent.

**Broader pattern:** Identify the top "next page" transitions and pre-warm their data:
- Article → Author profile: warm author context
- Home feed → Article: already warm (article is in DB/Redis)
- Profile → Follows tab: warm follows list on profile load

**Files to modify:**
- `src/Controller/Reader/ArticleController.php` — dispatch warm message in shutdown function
- Create `src/Message/WarmUserContextMessage.php` + handler that calls `fetchUserContext()`

---

### 7.4 Relay Response Deduplication at Pool Level

**Problem:** When querying multiple relays (e.g., 3 content relays + local), the same event can be returned by all of them. Currently, deduplication happens per-caller (each handler has its own `$seenIds` set). Events are deserialized and processed before being discarded as duplicates.

**Solution:** Move deduplication into `NostrRelayPool::sendToRelays()`. After collecting responses from all relays, deduplicate by event ID before returning to the caller. This saves the caller from iterating over and deserializing duplicate events. Add a `deduplicate: true` flag (default true) to `sendToRelays()`.

**Expected impact:** Reduces processing overhead proportional to the number of relays queried. With 4 relays, up to 75% of response events could be duplicates for popular content.

---

### 7.5 Conditional Fetch with `since` Based on Local State

**Problem:** Many fetch paths use `since: 0` (all time) or a fixed lookback window. When the local DB already has events up to timestamp T for a given author+kind, fetching everything from the beginning is wasteful.

**Solution:** Before dispatching a fetch, query the local DB for the most recent `created_at` for the relevant (pubkey, kind) pair. Use that as the `since` parameter. This is especially valuable for `FetchAuthorContentHandler` and `SyncUserEventsHandler`.

**Implementation:**
1. Add `EventRepository::getLatestTimestamp(string $pubkey, array $kinds): ?int`
2. In `FetchAuthorContentHandler::__invoke()`, set `$since = $eventRepo->getLatestTimestamp($pubkey, $allKinds) ?? 0`
3. In `SyncUserEventsHandler`, same pattern — check what we already have before fetching

**Expected impact:** Dramatically reduces data transfer for returning users. An author with 50 articles already cached will only fetch new ones instead of re-downloading all 50.

---

### 7.6 Service Worker Coordination for Client-Side Deduplication

**Problem:** The service worker (`public/service-worker.js`) already implements stale-while-revalidate for article and profile URLs. But there's no coordination between server-side relay fetches and client-side cache — the browser may request data that the server is simultaneously fetching from relays.

**Solution:** Use the Mercure SSE connection (already established for relay auth) to push cache-invalidation hints to the service worker. When `SyncUserEventsHandler` finishes syncing, publish a Mercure update to `cache-refresh/{pubkey}`. The service worker listens on this topic and proactively purges stale entries for that user's profile/articles.

**Implementation:**
- Extend `SyncUserEventsHandler` to publish a Mercure update on completion
- Add a `message` event listener in the service worker for `CACHE_INVALIDATE` with URL patterns
- The existing `handleCacheRefresh()` function in the service worker already supports selective cache clearing

---

### 7.7 Follow-Pack Batch Fetch

**Problem:** Follow packs (kind 39089) contain lists of pubkeys. When displaying a follow pack, each member's profile is resolved individually via `UserFromNpub`. For a pack of 30 members, this is 30 sequential metadata lookups.

**Solution:** When loading a follow pack event, extract all `p` tag pubkeys, call `RedisCacheService::getMultipleMetadata()` once, and pass the resulting map to the template. The template `event/_kind39089_followPack.html.twig` already passes `followPackProfiles[person]` as `:user` to `UserFromNpub` — the infrastructure is ready, just needs the batch fetch upstream.

**Files to modify:**
- Wherever follow pack events are rendered (admin follow packs page, profile follow packs tab)
- Ensure `getMultipleMetadata()` is called with all pack member pubkeys before template rendering

---

### 7.8 Parallel Relay Queries with Early Return

**Problem:** `NostrRelayPool::sendToRelays()` queries relays sequentially via `TweakedRequest::send()` — it iterates `foreach ($this->relays->getRelays() as $relay)` and waits for each relay's full EOSE before moving to the next. A slow relay (2s timeout) blocks responses from faster relays.

**Solution:** For read queries (REQ, not EVENT publishes), open connections to all relays simultaneously and process responses as they arrive. Return results to the caller as soon as EOSE is received from at least one relay, while continuing to collect from slower relays in the background. This is the "fastest relay wins" pattern used by mature Nostr clients.

**Implementation considerations:**
- PHP's blocking I/O model makes true parallel WebSocket handling complex
- Two approaches: (a) use `stream_select()` with non-blocking sockets, or (b) use the relay gateway which already manages multiple connections concurrently
- For the gateway path, this is already partially implemented — the gateway processes all connections in a single event loop. The optimization would be returning partial results to the caller early.

**Expected impact:** P95 response time for relay fetches drops from "slowest relay" to "fastest relay" latency.

---

### 7.9 Stale-While-Revalidate for Social Context

**Problem:** The `StaleWhileRevalidateCache` pattern is used for Unfold sites and profile tabs, but not for article social context (comments, highlights, reactions). Every article page visit triggers a live relay query for social data.

**Solution:** Cache the social context bundle (from Phase 2's `fetchArticleSocial()`) in Redis with SWR semantics:
- Fresh TTL: 2 minutes (comments are somewhat time-sensitive)
- Stale TTL: 30 minutes (serve stale comments while refreshing in background)
- Cache key: `social:{coordinate}`

The Comments live component already has a "load more" / refresh action — this would just serve cached data on first render and refresh asynchronously.

**Expected impact:** Repeat visits to the same article within 30 minutes serve instantly from cache. First visit within 2 minutes serves without any relay query.

---

### 7.10 Proactive strfry-to-DB Projection for Social Kinds

**Problem:** The strfry router already ingests social kinds (1111, 9735, 9802, 7) from external relays. The subscription workers persist articles and media to the DB, but comments, zaps, and highlights are only persisted when they're fetched on-demand (article page visit). This means the first visitor to an article page always pays the relay query cost.

**Solution:** Add a subscription worker (or extend the existing article worker) that listens for social kinds on the local strfry relay and projects them into the DB proactively. When a kind 1111 event arrives referencing a known article coordinate, persist it immediately. This way, article pages can load social context from DB without any relay query.

**Files to create/modify:**
- Add social kind processing to the article subscription worker in `src/Command/` (the one that runs via `app:run-relay-workers`)
- Reuse existing `EventRepository::saveEvent()` for persistence
- Filter to only persist events referencing known article coordinates (avoid storing orphaned comments)

**Expected impact:** Combined with SWR cache (7.9), this makes article social data available from DB on first visit, eliminating relay queries for social context entirely.

---

### 7.11 HTTP ETag / Conditional Responses for Article Pages

**Problem:** Article content (kind 30023) is immutable once signed — the event ID is a hash of the content. Yet every request re-renders the full Twig template (CommonMark conversion, highlight fetching, etc.). The `EventController` already sets ETags, but `ArticleController` (the main article view) does not.

**Solution:** After rendering an article page, compute an ETag from the article event ID + the count/timestamp of comments and highlights. Set `Last-Modified` from the article's `published_at`. On subsequent requests, check `If-None-Match` / `If-Modified-Since` and return 304 when nothing changed. This is free performance for returning visitors and crawler traffic.

**Implementation:**
- In `ArticleController::authorArticle()`, after assembling template data, call `$response->setEtag(md5($article->getEventId() . count($highlights)))` and `$response->isNotModified($request)`
- The browser skips re-downloading ~50–200 KB of HTML
- CDN / reverse proxy (Caddy) can cache 200 responses and serve 304s itself

**Expected impact:** 50–80% reduction in rendered bytes for repeat article visits (browser cache hit). Critical for SEO crawlers that re-check pages frequently.

---

### 7.12 Gateway-Mediated Batch Multi-Author Fetch

**Problem:** The "For You" / follows feed needs articles from N followed authors. Currently, if not in the DB, each author triggers a separate `FetchAuthorContentMessage`. With 50 follows, that's 50 async messages, each opening relay connections independently.

**Solution:** Instead of dispatching N individual messages, dispatch a single `FetchFollowsFeedMessage` containing all followed pubkeys. The handler builds one REQ with `authors: [pubkey1, pubkey2, ..., pubkeyN]` and `kinds: [30023]`, sent through the relay gateway. The gateway already manages persistent connections, so this is a single REQ to each relay covering all authors at once.

**Implementation:**
- Create `FetchFollowsFeedMessage` with `pubkeys[]` and `since`
- Handler uses `NostrRelayPool::sendToRelays()` with a combined filter
- Most relays support multi-author filters efficiently (indexed by pubkey)
- Forward received events to strfry for the subscription workers to pick up

**Expected impact:** Follows feed fetch goes from N relay connections to 1 (per relay). For a user following 100 authors, this is ~100x fewer WebSocket handshakes.

**Risk:** Some relays may reject very large `authors` arrays. Split into chunks of 50 if needed.

---

### 7.13 Turbo Frame Prefetch Hints via Link Headers

**Problem:** When the home page loads, the initial tab content is fetched lazily via Turbo Frame (`loading="lazy"`). The browser doesn't start this fetch until the frame enters the viewport, adding a visual delay.

**Solution:** Add a `Link: <url>; rel=prefetch` HTTP header on the home page response pointing to the default tab URL. The browser will speculatively fetch the tab content while rendering the outer shell. By the time the Turbo Frame triggers its fetch, the response is already in the browser's HTTP cache.

**Implementation:**
- In `DefaultController::index()`, add `$response->headers->set('Link', '<' . $this->generateUrl('home_feed_tab', ['tab' => 'latest']) . '>; rel=prefetch')` 
- Same pattern for `/multimedia` → first media tab
- Zero relay-side cost; purely a browser-side optimization

**Expected impact:** Perceived tab load time drops by 100–300ms (eliminates the browser's discovery latency).

---

### 7.14 Redis Pipeline for Bulk Social Counts from DB

**Problem:** Once article social events are projected into the DB (via 7.10), fetching comment/reaction/zap counts for an article list page still requires N SQL queries (one per article coordinate). This is the same N+1 problem as metadata, but for counts.

**Solution:** Precompute social counts as a Redis hash during the social projection step. When a kind 1111/7/9735 event is persisted, also `HINCRBY social_count:{coordinate} comments 1` (or `reactions`, `zaps`). Article list controllers then call `HMGET` for all visible coordinates in one round-trip.

**Implementation:**
1. In the social projection worker (from 7.10), after persisting a social event, increment the Redis counter
2. Add `SocialCountStore::getCountsForCoordinates(array $coords): array` — single `MGET` or pipeline
3. Pass counts into `CardList` template; the Card template renders badges

**Expected impact:** Social counts on article cards with zero per-request SQL or relay cost. Counter updates are amortized into the event ingestion pipeline.

---

### 7.15 Subscription Fan-In for Referenced Articles

**Problem:** When an article references other articles (via `a` tags, naddr embeds), the `Converter::prefetchNostrData()` method fetches each referenced event individually. For an article with 10 embedded naddr links, this is 10 sequential relay lookups (or DB queries with relay fallback).

**Solution:** `prefetchNostrData()` already collects all referenced coordinates upfront. Ensure the batch fetch for `naddrCoords` uses a single combined REQ with multiple `#a` filters or a combined `ids` filter, rather than iterating one-by-one. The method already does some batching for pubkeys (metadata) and event IDs — extend the same pattern to naddr coordinates.

**Implementation:**
- In `Converter::prefetchNostrData()`, after collecting `$naddrCoords`, build one REQ with `kinds + #d + authors` filters (one filter per coordinate in the REQ array)
- Send via `NostrRelayPool::sendToRelays()` to the local relay first
- Fall back to external relays only for coordinates not found locally

**Expected impact:** Articles with many embedded references render 5–10x faster on first view.

---

### 7.16 Outbox-Model Write Optimization

**Problem:** When publishing an event (article, comment, reaction), the app publishes to all of the user's write relays plus system relays. Each publish is a separate WebSocket connection (via `NostrRelayPool::publish()`), and the response is awaited synchronously.

**Solution:** For writes, adopt a fire-and-forget pattern with confirmation tracking:
1. Publish to the local strfry relay synchronously (guaranteed fast, ~1ms)
2. Dispatch the external relay publishes as a low-priority async message
3. Track which relays confirmed (via `OK` response) in `RelayHealthStore`
4. Show the user instant feedback after the local publish; display relay confirmation status asynchronously via Mercure

This means the user sees their article/comment immediately (it's in the local relay and DB), while external relay propagation happens in the background.

**Files to modify:**
- `NostrRelayPool::publish()` — split into `publishLocal()` (sync) + `publishExternal()` (async)
- Create `PublishToExternalRelaysMessage` + handler
- Article editor and Comments component — show local-publish success instantly

**Expected impact:** Publish latency drops from 2–10s (waiting for slowest relay) to <100ms (local relay only). External propagation happens within seconds asynchronously.












