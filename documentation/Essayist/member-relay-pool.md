# Essayist Member Relay Pool

## What this adds

A new utility service, `App\Service\Essayist\EssayistMemberRelayPoolService`, builds a deduplicated relay pool from current `ROLE_ESSAYIST_MEMBER` users.

This pool is intended for member-scoped REQ fan-out (articles and activity), without mutating global relay defaults.

## Data sources

- Member list: `app_user` rows with `ROLE_ESSAYIST_MEMBER` via `UserEntityRepository::findByRoleWithQuery()`.
- Relay declarations: cached NIP-65 data in `user_relay_list` via `UserRelayListRepository::findByPubkeys()`.

## Pool rules

- Uses member write relays first (`writeRelays`), fallback to all relays if write relays are missing.
- Deduplicates with normalized relay URLs (`RelayUrlNormalizer`).
- Filters out non-WebSocket and local/private endpoints (`localhost`, `127.0.0.1`, `strfry`).
- Orders by relay health score (`RelayHealthStore`) and caps the pool size.
- Caches in Redis for 6 hours (`essayist_member_relay_pool:v1`).

## Where it is used

The pool is a utility service for member-scoped fetch workflows (for example,
member activity aggregation) where fan-out beyond a single relay is desirable.

`EssayistFeedService` is intentionally excluded from this behavior and remains
single-relay (internal `strfry-essayist`) for exclusive-content reads.

## Why

This improves coverage for member-authored articles and related activity when members publish outside a single relay, while keeping fan-out scoped and controlled for Essayist-specific views.


