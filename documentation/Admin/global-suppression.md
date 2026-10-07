# Permanent author suppression

`admin:ban-es-authors` imports **author pubkeys**, not document IDs, from an Elasticsearch search response (`hits.hits[*]._source.pubkey`). It permanently blocks those authors at local ingestion, adds the existing instance-wide `ROLE_MUTED`, and deletes all their stored content and Elasticsearch article documents. It does not delete only the articles in the export.

## Import an Elasticsearch export

Deploy the code and apply the database migration before importing:

```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

Copy your exported JSON into the PHP container. For example, from PowerShell on Windows:

```powershell
docker compose cp "C:\path\to\search.json" php:/tmp/search.json
docker compose exec php bin/console admin:ban-es-authors /tmp/search.json --dry-run
docker compose exec php bin/console admin:ban-es-authors /tmp/search.json --reason="Spam galleries" --added-by="instance-operator" --confirm
```

Without `--confirm`, the command asks for confirmation, defaulting to no. Dry-run only lists target pubkeys and counts local rows; it does not query Elasticsearch, invalidate caches, persist bans, or delete anything.

Every hit must include a valid 64-character hexadecimal `_source.pubkey`. The complete file is validated before any writes, case is normalized, and duplicate authors are collapsed. Invalid JSON, malformed hits, failed shards, and timed-out responses are rejected.

An export is a snapshot, not a query. For example, a response reporting at least 10,000 matches but containing 10 hits only supplies the authors in those 10 hits. The command warns about partial result pages and does not automatically fetch more results or guess the original query. Export additional pages and rerun to cover additional authors.

## Persistence and retries

The `banned_pubkey` table retains the normalized pubkey, reason, creation time, and operator identifier independently of the original event/article rows and of `app_user`. Existing bans retain their original audit information on repeat imports. Existing user roles are preserved.

Bans and admin mutes are committed **before** cleanup. They remain active if Elasticsearch, cache invalidation, or database deletion fails. Rerunning the same export is safe and retries cleanup, including author-wide Elasticsearch deletion when local article rows have already disappeared.

When Elasticsearch is enabled, all matching author documents are removed using delete-by-query before database deletion. Search timeouts, version conflicts, and reported failures stop local deletion. With Elasticsearch disabled, cleanup only touches local stores; enable it and rerun the export to purge a separately retained index.

These are operator bans, not author-signed NIP-09 deletion requests, and never create `deleted_event` tombstones or signed kind-5 events. Removing `ROLE_MUTED` through the existing administration UI does **not** remove the persistent ingestion ban.

Core event/article/media/comment ingestion and graph projection check the ban table without caching misses. PostgreSQL triggers additionally reject banned-author inserts and author reassignment in article, event, highlight, magazine, and current-record stores, including direct DBAL writes.

Cleanup also deletes the authors' outgoing parsed references and current-record pointers. Article, profile/event, and hosted-publication cache pools, Redis `view:*` payloads, and the current environment's media discovery cache are invalidated before and after local deletion. Content caches are cleared instance-wide because other authors' reading lists and publications can embed the deleted items; sessions and unrelated Redis keys are not cleared.

## Content URL access

Public author/profile pages, article URLs (including draft URLs and lazy article frames), event addresses, chapter pages and parent frames, follow-pack pages, and `/api/fetch-article` reject instance-wide admin-muted or permanently banned authors with an ordinary HTTP **404**. No moderation reason is exposed. Personal NIP-51 mute lists do not affect these instance-wide decisions, and administration tools remain accessible.

When a URL or fetch request identifies the author (hex pubkey, npub, nprofile, naddr, nevent with an author hint, or an article coordinate), the check runs before content queries, metadata loading, relay-list discovery, relay requests, and asynchronous fetch dispatch. Vanity URLs require resolving the vanity name to its author first, then stop before content loading. Ban and admin-mute membership use fresh indexed database lookups, not the existing fail-open mute-list cache. Database lookup failures do not allow content access. Removing an admin mute does not make a banned author readable again.

An event-ID-only `note` or author-less `nevent` cannot identify its author without a lookup. These links are checked immediately after the event is found, before metadata loading, projection, or rendering. The fetch API also checks the actual author of fetched events, even when no author was supplied by the caller. Slug-only article disambiguation similarly requires a local lookup and excludes suppressed authors from its results.

## Operational boundaries

Back up production data before confirmation. Pause ingestion workers and indexing/population jobs during cleanup, and restart workers on the deployed code afterward. This prevents in-flight search writes from racing the purge; PostgreSQL triggers also protect direct database writes by older workers once the ban is visible. Bans affect all future event kinds from the author, including metadata, articles, and new revisions.

Local cleanup does not delete content from external Nostr relays or reclaim strfry disk space. See [Strfry Storage Maintenance](../Strfry/storage-maintenance.md). Ordinary admin mutes and personal NIP-51 mutes remain query-time controls; only this explicit import creates permanent ingestion bans.
