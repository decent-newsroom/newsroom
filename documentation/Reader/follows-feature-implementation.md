# Following Feed

`/follows` displays up to 50 recent articles from the signed-in reader's Nostr
contacts. Logged-out readers see a sign-in notice; empty follow lists and missing
articles have dedicated empty states.

## Data flow

1. `FollowsController` decodes the user's npub and reads the latest kind `3` event
   through `EventRepository::findLatestByPubkeyAndKind()`.
2. It extracts followed pubkeys from `p` tags. If the local event is missing,
   `UserProfileService::getFollows()` performs relay backfill so subsequent reads
   can use the database. Login sync also hydrates the follow list.
3. `ArticleRepository::findLatestByPubkeys()` retrieves complete articles,
   overfetches, and deduplicates revisions by coordinate before applying the limit.
4. `RedisCacheService::getMultipleMetadata()` supplies author metadata in a batch.

The `idx_article_pubkey_created` index supports the author/date query; migration
`Version20260320180000` creates it. Follow-list lookup is database-first, but a
cache miss can still incur relay latency.

## Integration

- `src/Controller/Newsroom/FollowsController.php`
- `templates/follows/index.html.twig`
- `src/Repository/ArticleRepository.php`
- `src/Service/Nostr/UserProfileService.php`

The [home feed](home-feed-logged-in.md) includes followed authors in its combined
Articles feed. Its old standalone Follows tab is not currently dispatched.
