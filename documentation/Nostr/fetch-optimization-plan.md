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












