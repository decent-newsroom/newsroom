# Fetch Optimization: Kind Bundles & Combined REQ

> **Status:** Implemented (Phases 0, 1, 2, 3, 5)  
> **Date:** 2026-03-19  
> **Goal:** Reduce relay round-trips by fetching related event kinds in combined REQ messages.

---

## Overview

This feature introduces **kind bundles** — named groups of related Nostr event kinds that are fetched together in a single relay REQ message. This dramatically reduces the number of relay round-trips needed to render pages.

### Impact Summary

| Page | Before | After |
|------|--------|-------|
| User profile page | 3–5 relay round-trips | 1 |
| Article page (social context) | 2 relay round-trips | 1 |
| Author content async fetch | 3–6 relay round-trips | 1 |

---

## Architecture

### KindBundles (`src/Enum/KindBundles.php`)

A final class with three constant arrays and helper methods:

| Bundle | Kinds | Use Case |
|--------|-------|----------|
| `USER_CONTEXT` | 0, 3, 5, 10000, 10001, 10002, 10003, 10015, 10020, 10063, 30015 | User identity, social graph, preferences |
| `ARTICLE_SOCIAL` | 7, 1111, 1985, 9734, 9735, 9802 | All social interactions for an article |
| `AUTHOR_CONTENT` | 20, 21, 22, 1111, 9802, 10003, 10015, 30003, 30004, 30005, 30006, 30015, 30023, 30024, 34139 | All publishable content for a profile |

Helper methods:
- `groupByKind(array $events)` — groups events by kind
- `latestByKind(array $events)` — returns only the latest event per kind
- `categorizeArticleSocial(array $events)` — categorizes into named buckets (reactions, comments, labels, zap_requests, zaps, highlights)

### AuthorContentType::fromKind()

Reverse-lookup method on the `AuthorContentType` enum. Given a Nostr event kind integer, returns the matching `AuthorContentType` case (or null). Used by the consolidated author content fetch to route events received from a combined REQ to their correct processing logic.

---

## Implementation Details

### Phase 1: Combined User Context Fetch

**`UserProfileService::fetchUserContext(string $pubkey, ?array $relays = null): array`**

Fetches all `USER_CONTEXT` kinds in one REQ for a given pubkey. Persists each received event to the DB. Returns `array<int, object>` keyed by kind.

The relay-fallback paths of `getFollows()`, `getInterests()`, and `getMediaFollows()` now call `fetchUserContext()` instead of issuing individual kind-specific REQs. On a DB miss for any of these kinds, all user context kinds are fetched and persisted at once. Subsequent calls for other kinds will hit the DB fast path.

### Phase 2: Combined Article Social Fetch

**`SocialEventService::fetchArticleSocial(string $coordinate, ?int $since = null): array`**

Fetches all `ARTICLE_SOCIAL` kinds filtered by `#A` tag in one REQ. Returns categorized arrays:
```php
[
    'reactions'    => [...],
    'comments'     => [...],
    'labels'       => [...],
    'zap_requests' => [...],
    'zaps'         => [...],
    'highlights'   => [...],
]
```

The existing `getComments()` and `getHighlightsForArticle()` methods remain available for callers that only need one type of social data.

### Phase 3: Consolidated Author Content Fetch

`FetchAuthorContentHandler::__invoke()` now collects all kinds from all requested content types and sends a single combined REQ with `limit: 500`. Received events are grouped by kind and routed to the correct processing logic via `AuthorContentType::fromKind()`.

### Phase 5: strfry Router Expansion

The `user_data` stream in `docker/strfry/router.conf` now ingests kinds 0, 3, 10000, 10001, 10002, 10003, 10015, 10020, 10063 from three relay sources (purplepag.es, nos.lol, relay.damus.io). This makes user context data available locally, so the DB-first lookup path in `getFollows()`, `getInterests()`, etc. hits more often without needing relay fallback.

---

## Files Changed

| File | Change |
|------|--------|
| `src/Enum/KindBundles.php` | **New** — Bundle definitions and helpers |
| `src/Enum/AuthorContentType.php` | Added `fromKind()` reverse-lookup |
| `src/Service/Nostr/UserProfileService.php` | Added `fetchUserContext()`; updated `getFollows()`, `getInterests()`, `getMediaFollows()` |
| `src/Service/Nostr/SocialEventService.php` | Added `fetchArticleSocial()` |
| `src/Service/Nostr/NostrClient.php` | Added facade methods `fetchUserContext()`, `fetchArticleSocial()` |
| `src/MessageHandler/FetchAuthorContentHandler.php` | Refactored to single combined REQ |
| `docker/strfry/router.conf` | Expanded `user_data` stream kinds and relay sources |

---

## Future Phases

See `documentation/Nostr/fetch-optimization-plan.md` for the full plan including:
- **Phase 4:** Local relay first for followed authors
- **7.1:** Batch metadata prefetch for article lists (N+1 elimination)
- **7.2:** NIP-45 COUNT for social metrics
- **7.5:** Conditional fetch with `since` based on local state
- **7.9:** Stale-while-revalidate for social context

