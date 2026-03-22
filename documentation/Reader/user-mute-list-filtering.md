# User Mute List Filtering (NIP-51 kind 10000)

## Overview

Logged-in users maintain a personal mute list (Nostr kind 10000, NIP-51) that specifies pubkeys, hashtags, words, and threads they don't want to see in their feeds. This mute list is synced from relays on login via `SyncUserEventsHandler` and stored in the local `event` table.

The application now respects the user's mute list when rendering article feeds, filtering out articles authored by muted pubkeys.

## Architecture

### Service

**`UserMuteListService`** (`src/Service/UserMuteListService.php`)

Reads the latest kind 10000 event for a given pubkey from the database and extracts muted pubkeys from `p` tags.

- Input: user's hex pubkey
- Output: array of muted hex pubkeys
- Graceful failure: returns empty array on any error (non-blocking)

### Where Filtering Is Applied

| Route | Controller | Method |
|-------|-----------|--------|
| `/home/tab/latest` | `HomeFeedController` | `latestTab()` |
| `/discover` | `DefaultController` | `discover()` |
| `/latest-articles` | `DefaultController` | `latestArticles()` |

### Filtering Strategy

Article feeds use a **two-tier exclusion** approach:

1. **Admin-level exclusion** (global, baked into cache):
   - Config deny-list (`newsroom.latest_articles.excluded_pubkeys`)
   - Admin-muted users (`ROLE_MUTED` via `MutedPubkeysService`)
   - Bot profile detection (via `LatestArticlesExclusionPolicy`)

2. **User-level exclusion** (per-user, applied at request time):
   - Personal mute list (kind 10000 `p` tags via `UserMuteListService`)
   - Applied as a post-filter on cached articles (Redis view store is shared/global)
   - Merged into the exclusion list for DB fallback queries

The Redis article cache (`view:articles:latest`) is **global** — it cannot contain per-user exclusions. Therefore, user-level mutes are applied as a post-filter when reading from cache, and merged into the query exclusion list on cache miss.

### Data Flow

```
Login → SyncUserEventsHandler → fetches kind 10000 → stored in event table
                                                          ↓
Feed request → UserMuteListService.getMutedPubkeys(pubkey)
                                                          ↓
                ┌─ Cache hit:  filter out muted pubkeys from cached articles
                └─ Cache miss: merge muted pubkeys into DB query exclusion list
```

## Mute List Event Structure (NIP-51)

```json
{
  "kind": 10000,
  "tags": [
    ["p", "<hex-pubkey-to-mute>"],
    ["p", "<another-hex-pubkey>"],
    ["t", "spam"],
    ["word", "scam"],
    ["e", "<thread-event-id>"]
  ]
}
```

Currently, only `p` (pubkey) tags are used for article feed filtering. Future enhancements could add support for `t` (hashtag) and `word` filtering.

## Related Fix: Redis View Cache Compression

The Redis view cache (`RedisViewStore`) previously used a **separate Redis key** (`<key>:compressed`) to track whether stored data was gzip-compressed. This caused silent cache corruption when the flag key expired or got out of sync with the data key. Symptoms: the cache appeared "lost" intermittently.

**Root cause:** Two separate Redis keys with independent TTLs. If the `:compressed` flag expired before the data (or survived from a previous write where data was compressed but the new write was uncompressed), the read path would either:
- Try to `gzuncompress()` raw JSON → fail → return `null`
- Pass compressed binary to `json_decode()` → fail → return `null`

**Fix:** Compression is now auto-detected from the data content. JSON always starts with `[` or `{`, while zlib-compressed data starts with `0x78`. No separate flag key needed. Corrupt entries are self-healing (deleted on read failure, rebuilt by the next cron run).
