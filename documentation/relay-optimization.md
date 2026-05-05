# Relay Infrastructure Optimization Analysis

> Written May 2026 following a conversation with a relay developer.
> Covers pain points experienced building this Nostr client and maps them to NIPs that address each one.

---

## Pain Points in Practice

### 1. Relay unreliability and health tracking

Relays go up and down constantly. We maintain a custom Redis-backed health store (`src/Service/Nostr/RelayHealthStore.php`) that tracks per-relay:
- `last_success` / `last_failure` timestamps
- `consecutive_failures` counter
- `avg_latency_ms` (exponential moving average)
- `auth_required` flag
- `auth_status` (none / ephemeral / user_authed / pending / failed)

This is a lot of client-side infrastructure just to know whether a relay is worth connecting to. Much of it could be eliminated if relays published reliable NIP-11 documents and NIP-66 liveness events.

### 2. NIP-42 AUTH friction

Authenticated relays add significant complexity. We currently discover `auth_required` at runtime — we connect, receive a NIP-42 challenge mid-session, then have to handle it. This runs as a separate Docker service (`relay-gateway`) with a persistent WebSocket pool. If a relay's NIP-11 document included `"limitation": { "auth_required": true }`, we could pre-seed this flag via a single HTTP fetch before opening any WebSocket, avoiding the wasted connection setup.

### 3. Hard-coded relay registry

Our relay list is defined in `config/services.yaml` parameters and managed in `src/Service/Nostr/RelayRegistry.php`. There is no dynamic relay discovery. We have no way to find relays that specifically store longform articles (kind 30023) or media (kinds 20/21/22) without maintaining the list manually.

### 4. No relay-side full-text search

We run an optional Elasticsearch sidecar (`ELASTICSEARCH_ENABLED`) with factory-selected implementations (`ArticleSearchInterface`). The `DatabaseArticleSearch` fallback works but is limited. Our local strfry relay indexes all longform articles. If relays supported NIP-50's `search` filter field, we could query `{"kinds": [30023], "search": "keyword"}` directly over the relay WebSocket and eliminate the Elasticsearch dependency for most use cases.

### 5. Multimedia content gaps

Kind 20/21/22 events (images, video, short video) are sparse on most relays. We run a 6-hour cron sweep that re-fetches everything and still misses content. There is no efficient way to know what we're missing in our local strfry cache compared to upstream relays.

### 6. Event replacement (NIP-33) races

Parameterized replaceable events (kind 30023 articles) can arrive out of order on different relays. We track `created_at` carefully ourselves to detect stale revisions, but different relays end up with different "latest" state for the same coordinate. No relay-side consistency guarantee exists.

### 7. Profile staleness

Profile data (kind 0) goes stale everywhere. We run a dedicated background profile refresh daemon (`app:run-profile-workers`) because there is no push invalidation — any profile update must be caught by polling.

### 8. Write confirmation unreliability

When publishing to multiple relays, results are inconsistent: one accepts, one rejects with no meaningful error, one times out. The NIP-01 OK message is implemented, but error reasons (`auth-required:`, `blocked:`, etc.) are inconsistently populated across relay implementations.

### 9. Zap receipts (kind 9735) are unfindable

We attempted to fetch zap receipts from relays and gave up — they are rarely present where expected. This feature is deferred entirely.

### 10. No standard relay-to-relay replication

strfry has its own router config for syncing between relays, but it is non-standard. Content silos form naturally. Fetching a full thread (comments, highlights, zaps for an article) requires multiple round-trips to different relay sets with no coordination.

---

## NIP Mapping: What Helps

### NIP-11 — Relay Information Document

**Addresses:** Pain points 2, 4 (auth discovery, wasted fetches)

The most impactful low-effort fix. Relays should publish complete `limitation` objects including:

- `auth_required` — we currently discover this mid-connection at runtime
- `default_limit` — if unset, we don't know whether a bare `REQ` returns 20 or 5000 events
- `max_limit` — tells us whether pagination is necessary
- `retention` — if a relay purges longform articles after N days or limits to N events, we should know before querying

We also gate NIP-50 search queries behind `supported_nips`. Relays that omit this field get search queries blasted at them regardless, wasting bandwidth on both sides.

**Effort for relay operators:** Minimal — config/documentation change, no protocol work.

---

### NIP-50 — Search Capability

**Addresses:** Pain point 4 (full-text search infrastructure)

Adding a `search` field to `REQ` filters would let us query `{"kinds": [30023], "search": "nostr apps"}` directly over the WebSocket. Our local strfry already holds the article corpus. A basic FTS index on the relay side would let clients drop Elasticsearch clusters for most article search use cases.

The `domain:` extension is particularly useful: `{"search": "domain:decentnewsroom.com bitcoin"}` scopes search to verified NIP-05 authors on a given domain — something we cannot do at all today without custom infrastructure.

**Effort:** Moderate — FTS index + filter parsing extension.

---

### NIP-66 — Relay Discovery and Liveness Monitoring

**Addresses:** Pain points 1, 3 (health tracking, hard-coded registry)

If relay operators (or monitoring bots) published kind `30166` events with:
- RTT measurements (`rtt-open`, `rtt-read`, `rtt-write`)
- Accepted kinds (`k` tags — e.g. `["k", "30023"]`)
- Supported NIPs (`N` tags)
- Auth/payment requirements (`R` tags)

We could subscribe to these and replace our hard-coded `RelayRegistry` and bespoke `RelayHealthStore` with a live, crowd-sourced relay directory. The `k` tag indicating accepted kinds is especially valuable — we could find relays that store longform articles without trial and error.

A monitoring bot that pings relays and publishes `30166` is a weekend project. The heavy lifting is client-side consumption.

**Effort for relay operators:** Low — just publish kind `30166` events. The monitoring infrastructure lives in a bot, not the relay itself.

---

### NIP-77 — Negentropy Syncing

**Addresses:** Pain points 5, 10 (multimedia content gaps, no standard relay-to-relay sync)

This is the highest-impact relay-side change. **strfry already supports `NEG-OPEN`**. The gap is upstream relays.

Negentropy allows two parties to reconcile their event sets using range-based set reconciliation — exchanging only fingerprints until differences are identified, then fetching only the missing events. For our use case:

- We subscribe to local strfry for kinds 20/21/22 (media). Coverage is incomplete because upstream relays are queried with blind `REQ` filters.
- If an upstream relay supports NIP-77, we send `NEG-OPEN` with `{"kinds": [20, 21, 22]}` and efficiently learn exactly what that relay has that we don't — without re-downloading the entire set.
- This replaces our 6-hour brute-force cron sweep with an efficient diff.

For relay-to-relay sync: strfry can already use negentropy in its router config. If upstream relays implement it too, strfry's sync becomes a proper set reconciliation rather than a full re-scan.

Reference implementations exist in C, Go, and JavaScript (hoytech/negentropy).

**Effort:** Moderate — Negentropy library integration + `NEG-OPEN` / `NEG-MSG` / `NEG-CLOSE` message handling.

---

### NIP-86 — Relay Management API

**Addresses:** Admin suppression integration (our backlog item)

We are building a global pubkey ban system: a `banned_pubkey` table, an ingestion-time check in the event projector, and a `bans:reap` command. This is application-level — it stops us projecting events from banned pubkeys into our PostgreSQL database.

However, banned pubkeys can still write to our local strfry relay and have their events accepted there. If strfry implemented NIP-86, our planned `admin:ban-pubkey` CLI command could additionally call `banpubkey` on the relay via a standard HTTP+RPC call — blocking new events at the WebSocket transport layer before they reach our projector.

Currently strfry manages this only via `write-policy.sh`, which requires container access and is not addressable from application code.

**Effort:** Low-to-moderate — HTTP endpoint + NIP-98 authorization + method dispatch.

---

### NIP-62 — Request to Vanish

**Addresses:** GDPR-adjacent compliance, permanence of deletions

NIP-09 (kind 5) deletions are soft deletes that relays often ignore or that get re-introduced via relay-to-relay sync. Kind `62` is a stronger signal: delete everything for this pubkey, including the deletion events themselves, and block re-ingestion permanently.

We already handle NIP-09 in `EventDeletionService`. Adding NIP-62 support follows the same code path with broader scope (full pubkey history purge + permanent re-ingestion block). Legally relevant for GDPR right-to-erasure in EU jurisdictions where the relay operator may have legal obligations.

**Effort:** Low — extends the existing NIP-09 deletion handler.

---

## Concrete Issue in This Codebase

### Highlight-to-article mapping bug (client-side, fixable today)

**Root cause identified by reading NIP-84.** Highlights can reference source events via `a` tags (coordinate) OR `e` tags (event ID). Some clients (Habla, Highlighter) use `e` tags. Our handlers only look for `a` tags with a `30023:` prefix:

```php
// SaveHighlightsHandler.php:57 — too narrow
if (str_starts_with($tag[1] ?? '', '30023:')) {
    $articleCoordinate = $tag[1];
}
```

Highlights with `e` tags arrive, get saved with `articleCoordinate = null`, and then `findByArticleCoordinate()` returns nothing when rendering the article page. This explains the AGENTS.md bug: *"I see a highlight of an article, but no highlights when I get to the article page."*

Additional inconsistency: `FetchHighlightsCommand` already uses `preg_match('/^30\d+:/', ...)` which correctly handles non-30023 kinds (e.g. 30024 drafts promoted to published). `SaveHighlightsHandler` and `FetchHighlightsHandler` are still hardcoded to `30023:`.

**Fix needed (us, not the relay dev):**
1. Broaden `a` tag matching to `preg_match('/^30\d+:/', ...)` in all handlers for consistency.
2. Handle `e` tags: resolve the event ID against the local database/event store to recover the article coordinate before saving.

---

## Recommendations for the Relay Dev

Priority order based on concrete client impact:

| Priority | NIP | Effort | Impact |
|----------|-----|--------|--------|
| 1 | **NIP-11 completeness** | Minimal | Every client benefits immediately — `auth_required`, `retention`, `supported_nips` |
| 2 | **NIP-77 Negentropy** | Moderate | Solves multimedia content gaps; enables efficient relay-to-relay sync |
| 3 | **NIP-50 Search** | Moderate | Eliminates Elasticsearch dependency for longform article search |
| 4 | **NIP-66 Liveness events** | Low (monitoring bot) | Enables dynamic relay discovery, replaces hard-coded client registries |
| 5 | **NIP-86 Management API** | Low–moderate | Standard ban/allow interface; integrates with client admin tooling |
| 6 | **NIP-62 Vanish** | Low | Legal compliance; extends existing NIP-09 handler |

**Highest-value, lowest-effort ask: complete NIP-11 documents.** It is a configuration change that immediately improves every client's connection logic at no protocol cost.

**Highest-engineering-value ask: NIP-77 Negentropy.** It eliminates the brute-force polling pattern that causes content gaps and wastes bandwidth across the entire ecosystem. strfry already supports it — the bottleneck is the other side of the sync.

