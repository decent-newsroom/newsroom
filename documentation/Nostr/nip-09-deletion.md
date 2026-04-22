# NIP-09: Event Deletion Requests

This client honors NIP-09 kind `5` deletion requests both at ingestion time
(events received from relays) and on the outbound side (user-initiated deletes
in the editor / admin, to be implemented per controller — see *Outbound* below).

## Overview

A kind `5` event asks the network to delete one or more events the author
previously published. Its `tags` carry:

- `["e", <event-id>]` — a concrete event to delete
- `["a", "<kind>:<pubkey>:<d>"]` — a replaceable coordinate; all versions with
  `created_at <= deletion.created_at` should be removed
- `["k", <kind>]` — optional hint for the kind of each referenced event
- `content` — optional human-readable reason

Per spec, a client **MUST** verify that the `pubkey` of each referenced event
equals the deletion request's `pubkey` before acting on it. Relays cannot be
trusted with this check.

## Ingestion flow

1. An event arrives (via `GenericEventProjector` from worker subscriptions, or
   in bulk via `PersistGatewayEventsHandler` from the relay gateway).
2. **Shadow-ban check** (`DeletedEventRepository::isSuppressed`): if the event
   matches an existing tombstone by id, or its coordinate
   (`kind:pubkey:d`) matches an `a`-tombstone whose `deletion_created_at >=`
   the event's `created_at`, the event is silently dropped. This prevents
   re-ingestion of deleted content.
3. If the event itself has `kind === 5`, it is persisted and then handed to
   `EventDeletionService::processDeletionRequest()`.

## Processing a deletion request

`App\Service\EventDeletionService` iterates the request's tags:

- For each `e` tag: look up the referenced event. If found and its `pubkey`
  equals the requester's `pubkey`, cascade-delete it. Skip silently on mismatch.
- For each `a` tag: parse `kind:pubkey:d`. Skip if the pubkey differs from the
  requester. Cascade-delete every row whose `pubkey`/`kind`/`d_tag` match and
  whose `created_at <= request.created_at`.
- Targets of kind `5` are ignored (NIP-09 §"Deletion Request of a Deletion
  Request").

A `deleted_event` tombstone row is written for every honored reference — even
when the target is not yet stored locally — so late-arriving re-publishes get
suppressed by step 2 above.

## Cascades by kind

| Kind(s) | Action |
|---|---|
| 30023 / 30024 | delete `Article` rows matching `pubkey` + `slug` (≤ `created_at` on `a`) |
| 9802 | delete `Highlight` rows by `event_id` or `pubkey` |
| 30040 / 30041 | delete `Magazine` row by slug (= d-tag) |
| any | delete `Event` rows matching the scope |

## Tombstone schema

Table `deleted_event`:

| Column | Type | Notes |
|---|---|---|
| `id` | serial PK | |
| `target_ref` | varchar(512), unique | event id hex, **or** `kind:pubkey:d` coordinate |
| `ref_type` | char(1) | `e` or `a` |
| `pubkey` | varchar(64) | deletion requester (== target author) |
| `kind` | int null | referenced kind (from `k` tag or resolution) |
| `deletion_event_id` | varchar(64) | id of the kind:5 event |
| `deletion_created_at` | bigint | suppression window upper bound for `a` refs |
| `reason` | text null | from `content` of the kind:5 event |
| `recorded_at` | timestamp | |

Tombstones persist indefinitely: NIP-09 requires that clients keep broadcasting
the deletion, and we must keep rejecting re-ingests.

## Backfill: applying deletions you already stored

The ingestion hook only processes kind:5 events at the moment they arrive. For
kind:5 events that were already persisted before this handling existed (or that
arrived while the service was broken), run the one-shot replay command:

```bash
# Replay every kind:5 in the database (idempotent; safe to re-run)
docker compose exec php bin/console events:replay-deletions

# Preview what would happen without touching the DB
docker compose exec php bin/console events:replay-deletions --dry-run

# Scope to a single author or a time window
docker compose exec php bin/console events:replay-deletions --pubkey=<hex>
docker compose exec php bin/console events:replay-deletions --since=1735689600 --limit=500
```

The command walks kind:5 rows oldest-first (so if the same coordinate has
multiple deletion requests, the newest one's `created_at` wins in the
tombstone), batches 100 at a time, and routes each one through
`EventDeletionService::processDeletionRequest()` — so the cascade and the
tombstoning are exactly the same as the live ingestion path.

## Outbound (user-initiated deletion)

The ingestion / processing half is complete. Publishing a kind:5 event when a
user deletes their own article, highlight, or magazine from the UI is left to
the respective controllers — they should build and sign a kind:5 event
(browser-side via NIP-07/46), POST it back to an endpoint that calls
`EventDeletionService::processDeletionRequest()`, and additionally broadcast
it to the user's write relays via `NostrClient`. Local removal then happens
as a side effect of the same processing path — no separate delete code is
required.

## References

- [`documentation/NIP/09.md`](../NIP/09.md) — upstream spec
- [`src/Service/EventDeletionService.php`](../../src/Service/EventDeletionService.php)
- [`src/Entity/DeletedEvent.php`](../../src/Entity/DeletedEvent.php)
- [`tests/NIPs/NIP-09.feature`](../../tests/NIPs/NIP-09.feature)

