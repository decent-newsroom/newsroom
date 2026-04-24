# Notifications Center

Per-user notification feed with real-time Mercure toasts. Scope is deliberately narrow: users subscribe to **sources** (npub / publication / NIP-51 set) and are notified **only** when a new **long-form article (kind 30023)** or **publication index (kind 30040)** from that source is ingested by the application.

Out of scope by design:

- Chapters (kind 30041) — emitted in bursts alongside the 30040 index; per-chapter toasts would spam.
- Highlights, comments, reactions, zaps, chat, DMs, media — these belong on other Nostr clients. This client notifies about long-form content.
- Implicit "someone mentioned / replied to / zapped / reacted to me" notifications. Not built.
- Email or push (VAPID) digests.

## Data model

Two tables; migration `Version20260423120000`.

### `notification_subscription`

| column | type | notes |
|---|---|---|
| `id` | int PK identity |  |
| `user_id` | int FK → `app_user(id)` | `ON DELETE CASCADE` |
| `source_type` | varchar(32) | `npub` / `publication` / `nip51_set` (see `App\Enum\NotificationSourceTypeEnum`) |
| `source_value` | varchar(512) | hex pubkey for `npub`; `kind:pubkey:d` coordinate for the other two |
| `label` | varchar(255) nullable | user-visible hint (stored when adding via `naddr1…`) |
| `active` | bool, default true |  |
| `created_at` | timestamp |  |

Unique constraint: `(user_id, source_type, source_value)`. Indexes: `(active, source_type)` for worker-style filter rebuilds, `(user_id)` for per-user listing.

### `notification`

| column | type | notes |
|---|---|---|
| `id` | bigint PK identity |  |
| `user_id` | int FK → `app_user(id)` | `ON DELETE CASCADE` |
| `subscription_id` | int FK → `notification_subscription(id)` nullable | `ON DELETE SET NULL` |
| `event_id` | varchar(64) | Nostr event id (hex) |
| `event_kind` | int | always 30023 or 30040 in v1 |
| `event_pubkey` | varchar(64) |  |
| `event_coordinate` | varchar(512) nullable | `kind:pubkey:d` when addressable |
| `title` | varchar(512) nullable | from `title` / `name` / `alt` tag |
| `summary` | varchar(1024) nullable | from `summary` tag |
| `url` | varchar(1024) | generic event deep link (`/e/{nip19}`) |
| `created_at` | timestamp | Nostr event `created_at`, NOT ingestion time |
| `seen_at` | timestamp nullable | cleared when user opens `/notifications` |
| `read_at` | timestamp nullable | per-item read marker |

Dedup: unique `(user_id, event_id)` — a user cannot receive two notifications for the same event even if two of their subscriptions both match. Extra indexes: `(user_id, read_at)` for unread count, `(user_id, created_at)` for the feed.

## Ingestion & fan-out

No dedicated relay worker. Notifications piggy-back on the existing projection pipeline.

```
(any ingestion path: subscription worker, RSS importer, ad-hoc fetch, gateway)
    ↓
GenericEventProjector::projectEventFromNostrEvent()   ← persists the Event
    ↓                                                   (only for kinds 30023 / 30040)
MessageBus::dispatch(new FanOutNotificationMessage($eventId))   → async transport
    ↓
FanOutNotificationHandler
    ├── reload Event from DB
    ├── NotificationMatcher::isNotifiedKind()  ← hard kind gate, defence in depth
    ├── NotificationMatcher::match(Event) → NotificationSubscription[] (deduped per user)
    ├── for each match:
    │     ├── NotificationRepository::existsForUserAndEvent()  ← app-level dedup
    │     ├── persist Notification (unique constraint backs up race loss)
    │     └── Hub::publish(Update($topic, $json, private: true))
    └── Mercure topic = /users/{userId}/notifications
```

### Match logic (`NotificationMatcher::match`)

- **NPUB:** any subscription whose `source_value == event.pubkey`.
- **PUBLICATION:** match when either the event **is** a kind:30040 whose coordinate equals `source_value`, **or** the event carries an `a` tag equal to `source_value`. Publication chapters (kind:30041) never reach the matcher because the kind gate rejects them first.
- **NIP51_SET:** resolve the set event via `EventRepository::findByNaddr(kind, pubkey, d)`, split its tags into three buckets — `p` → pubkeys, `a` → coordinates, `t` → lowercased hashtags. The event matches the set if its pubkey is in `pubkeys`, its coordinate is in `coords`, or any of its `#t` tags intersect `tags`.

All matches are folded into an array keyed by `user_id` so a single user with overlapping subscriptions gets exactly one notification row and one Mercure update.

## Mercure delivery

The hub is already wired in `frankenphp/Caddyfile` (Bolt transport, `anonymous` + `subscriber_jwt` + `publisher_jwt`, 15 s heartbeat, gzip excluded on `/.well-known/mercure`). No Caddy change was needed.

- **Topic:** `/users/{userId}/notifications` — uses the numeric DB id, not the npub, so knowing a user's public identity does not let you guess the topic path.
- **Private:** `Hub::publish(new Update($topic, $json, true))`. The hub only forwards to a subscriber whose JWT explicitly lists this topic.
- **Subscriber JWT:** `MercureSubscriberTokenService::mintForUser($user)` — HS256, 8 h TTL, single-topic `subscribe` claim `["/users/{id}/notifications"]`. Signed with `%env(MERCURE_JWT_SECRET)%`.
- **Token delivery:** `MercureCookieSubscriber` (`kernel.response`, priority -16) attaches an HttpOnly, Secure, SameSite=Strict cookie `mercureAuthorization` scoped to `/.well-known/mercure` on authenticated HTML responses. The hub reads this cookie natively on SSE requests — no JS plumbing required (which matters because `EventSource` does not support an `Authorization` header). Refreshed when remaining TTL < 1 h.
- **Payload shape:**

```json
{
  "type": "notification",
  "id": 123,
  "kind": 30023,
  "title": "The article headline",
  "summary": "Optional summary from the summary tag",
  "url": "/article/naddr1…",
  "author": "<event pubkey, hex>",
  "createdAt": 1700000000,
  "unread": 7
}
```

The payload deliberately does **not** include the raw event body, to prevent leaking spammy or NSFW content through the toast channel.

### kind:30040 deep-link routing

- Notification URLs stay generic (`/e/naddr1...`).
- `EventController` owns the redirect decision after resolving the event payload:
  - nested kind:30040 `a` tags => `/mag/{slug}`
  - nested kind:30023/30024 `a` tags => `/p/{npub}/list/{slug}`
- If tags cannot classify the event, `/e/...` renders the generic event page.

## Frontend

- `assets/controllers/ui/notifications_stream_controller.js` — Stimulus identifier `ui--notifications-stream`. Opens `new EventSource(topicUrl, { withCredentials: true })`, routes every `{type:'notification'}` frame to `window.showToast(title, 'info')` (from the existing toast controller in `assets/controllers/utility/toast_controller.js`), and, on the `/notifications` page, prepends the new item to the list via the `list` target.
- Mounted globally on authenticated pages via a hidden `<div>` in `templates/base.html.twig`, so toasts fire no matter what page the user is on.
- `templates/notifications/index.html.twig` — the notifications feed, with `data-ui--notifications-stream-target="list"` on the list so arrivals are inserted at the top. Opening the page calls `NotificationRepository::markAllSeen($user)` to clear the unseen badge.
- `templates/notifications/subscriptions.html.twig` — add/remove manager. v1 input is a single text field: paste `npub1…`, `naddr1…`, a raw hex pubkey, or a raw `kind:pubkey:d` coordinate. `NotificationsController::parseIdentifier` normalises all four into a `(source_type, source_value, label)` triple. Both mutations are CSRF-gated (`notification-subscribe` / `notification-unsubscribe`).
- `assets/styles/03-components/notifications.css` — item and subscription list styles. No shading, no rounded edges (project convention).
- A `Notifications` link is added to `templates/components/UserMenu.html.twig` for authenticated users.

## Routes

| Method | Path | Name | Purpose |
|---|---|---|---|
| GET | `/notifications` | `notifications_index` | Feed page |
| GET | `/notifications/subscriptions` | `notifications_subscriptions` | Manage subs |
| POST | `/notifications/subscriptions` | `notifications_subscriptions_add` | Add (CSRF) |
| POST | `/notifications/subscriptions/{id}` | `notifications_subscriptions_remove` | Remove (CSRF) |
| GET | `/api/notifications/unread-count` | `api_notifications_unread_count` | JSON badge |
| POST | `/api/notifications/{id}/read` | `api_notifications_mark_read` | Per-item read |
| POST | `/api/notifications/mark-all-seen` | `api_notifications_mark_all_seen` | Clear badge |

All routes require `ROLE_USER`.

## Operator runbook

- **Inspect fan-out:** `docker compose logs worker | Select-String FanOutNotification`. Relevant log records: `Failed to dispatch FanOutNotificationMessage` (bus unreachable), `Failed to persist notification` (DB race or constraint), `Failed to publish notification to Mercure` (hub unreachable / JWT drift).
- **Verify JWT secret parity:** `MERCURE_JWT_SECRET` must be identical in the `php`, `worker`, and `worker-relay` services (see `compose.yaml`); a mismatch makes `publish` succeed but no subscriber ever sees the event.
- **Purge a spammer:** `DELETE FROM notification WHERE event_pubkey = '<hex>';` — safe because the unique-by-event-id constraint means re-ingestion of the same events won't recreate rows unless the user's subscription is still active.
- **Clear a user's subscriptions:** `DELETE FROM notification_subscription WHERE user_id = <id>;`

## Security

- Notifications are strictly private. Subscriber JWT `mercure.subscribe` claim is scoped to a single topic; `Update` is published with `private=true`; the hub enforces recipient matching.
- CSRF tokens on every subscription mutation.
- Payload omits raw event content; only rendered `title`, `summary`, `url`, and `author` hex are pushed.
- `MercureCookieSubscriber` only runs on main requests producing `text/html` responses (`kernel.response`, priority -16) and only when a `User` principal is present — no cookie on API/JSON/asset traffic.

## Known limitations / follow-ups

- Input picker is a single paste field; building in the existing user-search and publication-search pickers is a separate task.
- No rate limit on fan-out (yet). If an author floods the network, every subscriber gets every event. Track per-subscription token-bucket here if it becomes a problem.
- Retention: no pruning cron yet. A 90-day purge is a natural follow-up.
- NIP-51 set expansion is computed per event, not cached. If a heavily-subscribed set becomes a hot path, wrap `NotificationMatcher::expandSet` in a short-TTL Redis cache keyed by the set coordinate + the set event's `created_at`.

