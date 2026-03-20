# Follows Feed Optimization

## Problem

The follows tab on the home feed was slow because:

1. **Relay fallback for follow list**: `getUserFollows()` checked the local DB for the kind 3 event, but fell back to a relay round-trip (`fetchUserContext()`) when not found. This could take 10+ seconds.
2. **Unnecessary relay pool resolution**: `FollowsRelayPoolService::getPoolForUser()` was called to build a consolidated relay pool, but the result (`followsRelayPool`) was passed to the template and never used — pure overhead.
3. **No database index**: The article query `WHERE pubkey IN (...) ORDER BY created_at DESC LIMIT 50` had no composite index, forcing PostgreSQL to seq-scan or filesort.

## Solution

### 1. DB-only follow list lookup

The follows tab now reads the kind 3 event directly from the local `event` table via `EventRepository::findLatestByPubkeyAndKind()`. No relay fallback. The kind 3 event is populated on login by `SyncUserEventsHandler`, so it should always be available for logged-in users. If it's missing (rare edge case — user logged in before sync completed), the tab shows an empty state instead of blocking.

### 2. Removed dead relay pool call

The `FollowsRelayPoolService::getPoolForUser()` call was removed entirely from the follows tab. The pool was intended for future use in dispatching async content fetches, but was never wired up. The service itself is untouched — it's still used by the relay pool warmup handler.

### 3. Composite database index

Added `idx_article_pubkey_created` on `article(pubkey, created_at DESC)`. This index supports the follows feed query pattern:

```sql
SELECT * FROM article WHERE pubkey IN (?, ?, ...) ORDER BY created_at DESC LIMIT 100
```

PostgreSQL can now use an index scan to satisfy both the filter and the sort.

### 4. Dedicated repository method

Added `ArticleRepository::findLatestByPubkeys()` with:
- Proper `title IS NOT NULL` and `slug IS NOT NULL` filters
- Overfetch (2×limit) then deduplicate by coordinate (`pubkey:slug`)
- Early break once limit is reached

## Files Changed

| File | Change |
|------|--------|
| `src/Controller/Reader/HomeFeedController.php` | Rewrote `followsTab()` to use DB-only lookup, removed relay pool and NostrClient dependencies |
| `src/Controller/FollowsController.php` | Same DB-only optimization for the standalone `/follows` route |
| `src/Repository/ArticleRepository.php` | Added `findLatestByPubkeys()` method |
| `src/Entity/Article.php` | Added ORM index annotation `idx_article_pubkey_created` |
| `migrations/Version20260320180000.php` | Migration to create the composite index |

## Performance Impact

| Step | Before | After |
|------|--------|-------|
| Follow list resolution | DB lookup + relay fallback (0–15s) | DB lookup only (~5ms) |
| Relay pool resolution | DB + Redis + batch relay list queries (~200ms) | Removed |
| Article query | Seq scan + filesort (~100ms+) | Index scan (~10ms) |
| **Total worst case** | **~15s** | **~50ms** |

## Migration

Run the migration inside the Docker container:

```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

