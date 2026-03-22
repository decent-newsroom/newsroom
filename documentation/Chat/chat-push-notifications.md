# Chat Push Notifications — Implementation Plan

## Summary

Browser push notifications for chat messages, delivered when users are not actively viewing a chat group. Uses the Web Push API (RFC 8030) with VAPID keys, a Service Worker on the chat subdomain, and async delivery via Symfony Messenger.

The existing Mercure SSE flow is untouched — push is a parallel channel for background/offline users.

---

## Architecture

```
Message sent
  → ChatMessageService (existing)
    → publish to strfry-chat relay (existing)
    → publish Mercure SSE update (existing)
    → dispatch SendChatPushNotificationMessage (NEW — async via Messenger)

Messenger worker picks up message
  → SendChatPushNotificationHandler
    → load group members with push subscriptions
    → filter out: sender, muted members, expired subscriptions
    → check throttle (skip if push sent to this group <30s ago)
    → send Web Push via minishlink/web-push to each subscription endpoint

Browser (background/closed tab)
  → Service Worker receives push event
    → checks if chat tab is already visible (clients.matchAll)
    → if not visible: displays notification "New message in {groupName}"
    → notificationclick: opens/focuses the chat tab at /groups/{slug}
```

---

## Phase 1 — Infrastructure: VAPID Keys, Dependency, Config

### Install dependency

```bash
docker compose exec php composer require minishlink/web-push
```

### Generate VAPID keys

```bash
docker compose exec php php -r "
use Minishlink\WebPush\VAPID;
\$keys = VAPID::createVapidKeys();
echo 'VAPID_PUBLIC_KEY=' . \$keys['publicKey'] . PHP_EOL;
echo 'VAPID_PRIVATE_KEY=' . \$keys['privateKey'] . PHP_EOL;
"
```

### Environment variables (`.env`)

```dotenv
###> chat-push ###
VAPID_SUBJECT=mailto:admin@decentnewsroom.com
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
###< chat-push ###
```

### Bundle configuration

`config/packages/chat.yaml`:
```yaml
chat:
    relay_url: '%env(default:chat_relay_default:CHAT_RELAY_URL)%'
    vapid:
        subject: '%env(VAPID_SUBJECT)%'
        public_key: '%env(VAPID_PUBLIC_KEY)%'
        private_key: '%env(VAPID_PRIVATE_KEY)%'
```

Wire in `ChatExtension` and `Configuration`.

---

## Phase 2 — Entity: Push Subscriptions + Notification Mute

### `ChatPushSubscription` (new entity)

| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `chat_user_id` | FK → ChatUser | |
| `endpoint` | TEXT, unique | Push service endpoint URL |
| `public_key` | VARCHAR(255) | p256dh key from browser |
| `auth_token` | VARCHAR(255) | Auth secret from browser |
| `created_at` | datetime_immutable | |
| `expires_at` | datetime_immutable, nullable | From `PushSubscription.expirationTime` |

One user can have multiple subscriptions (multiple browsers/devices).

### `ChatGroupMembership` — add field

| Column | Type | Notes |
|--------|------|-------|
| `muted_notifications` | boolean, default false | Per-user per-group mute |

### Migration

Single migration for both changes.

---

## Phase 3 — Backend: Service, Messenger, Controller

### `ChatWebPushService` (new)

`src/ChatBundle/Service/ChatWebPushService.php`

- `dispatchPushNotification(ChatGroup, ChatUser $sender)` — dispatches `SendChatPushNotificationMessage` to Messenger
- Keeps the send flow non-blocking

### `SendChatPushNotificationMessage` (new)

`src/ChatBundle/Message/SendChatPushNotificationMessage.php`

Carries: `groupId`, `senderPubkey`, `senderDisplayName`, `groupName`, `groupSlug`, `communitySubdomain`.

**No message content** — for privacy, the push payload tells users *who* sent a message *where*, not *what*.

### `SendChatPushNotificationHandler` (new)

`src/ChatBundle/MessageHandler/SendChatPushNotificationHandler.php`

1. Load group by ID
2. Throttle check: Redis key `chat_push:{groupId}` with 30s TTL — skip if exists
3. Load all `ChatPushSubscription` rows for group members (join through `ChatGroupMembership`)
4. Filter out:
   - Sender's own subscriptions (by `senderPubkey`)
   - Members with `mutedNotifications = true`
5. Build `Minishlink\WebPush\WebPush` instance with VAPID credentials
6. Queue notifications with JSON payload:
   ```json
   {
     "type": "chat_message",
     "groupSlug": "general",
     "groupName": "General",
     "communitySubdomain": "oakclub",
     "senderDisplayName": "Alice"
   }
   ```
7. Flush batch
8. Handle `410 Gone` responses — delete stale subscriptions
9. Set throttle key in Redis

### Messenger routing

`config/packages/messenger.yaml`:
```yaml
routing:
    'App\ChatBundle\Message\SendChatPushNotificationMessage': async_low_priority
```

### `ChatPushController` (new)

`src/ChatBundle/Controller/ChatPushController.php`

| Route | Method | Purpose |
|-------|--------|---------|
| `/push/vapid-key` | GET | Returns VAPID public key as JSON |
| `/push/subscribe` | POST | Stores push subscription for current ChatUser |
| `/push/unsubscribe` | POST | Deletes subscription by endpoint |

All routes conditioned on `request.attributes.has('_chat_community')`.

### Extend `ChatSettingsController`

Add `POST /settings/groups/{slug}/mute-notifications` — toggles `ChatGroupMembership.mutedNotifications`.

---

## Phase 4 — Frontend: Service Worker + Stimulus Controller

### Service Worker: `public/chat-sw.js`

Separate from any main-app Service Worker. Chat subdomains register this one.

```js
// push event — display notification
self.addEventListener('push', (event) => {
  const data = event.data?.json() ?? {};
  
  // Suppress if chat tab is already visible
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(clients => {
        const chatTabVisible = clients.some(c => 
          c.visibilityState === 'visible' && 
          c.url.includes(`/groups/${data.groupSlug}`)
        );
        if (chatTabVisible) return; // user is already looking at it
        
        return self.registration.showNotification(
          `${data.groupName}`,
          {
            body: `${data.senderDisplayName} sent a message`,
            icon: '/favicon.ico',
            tag: `chat-${data.groupSlug}`, // collapse repeat notifications per group
            renotify: true,
            data: { url: `/groups/${data.groupSlug}` }
          }
        );
      })
  );
});

// notificationclick — open/focus the chat tab
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/groups';
  
  event.waitUntil(
    self.clients.matchAll({ type: 'window' })
      .then(clients => {
        const existing = clients.find(c => c.url.includes(url));
        if (existing) return existing.focus();
        return self.clients.openWindow(url);
      })
  );
});
```

### Stimulus controller: `assets/controllers/chat/chat_push_controller.js`

```
data-controller="chat--push"
data-chat--push-vapid-key-value="..."
data-chat--push-subscribe-url-value="/push/subscribe"
```

On `connect()`:
1. Check `'serviceWorker' in navigator && 'PushManager' in window`
2. Check `Notification.permission`:
   - `granted` → silently ensure subscription is current
   - `default` → show prompt banner (target element)
   - `denied` → hide prompt, do nothing
3. On user click "Enable notifications":
   - `Notification.requestPermission()`
   - Register Service Worker: `navigator.serviceWorker.register('/chat-sw.js')`
   - Subscribe: `registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey })`
   - POST subscription to `/push/subscribe`
4. urlBase64ToUint8Array helper for VAPID key conversion

### Template changes

**`layout.html.twig`** — mount the push controller + prompt banner:
```twig
<div data-controller="chat--push"
     data-chat--push-vapid-key-value="{{ vapidPublicKey }}"
     data-chat--push-subscribe-url-value="/push/subscribe">
  <div data-chat--push-target="prompt" class="chat-push-prompt" style="display:none;">
    <span>Enable notifications to know when new messages arrive</span>
    <button data-action="chat--push#requestPermission" class="btn btn--small">Enable</button>
    <button data-action="chat--push#dismissPrompt" class="btn btn--small btn--muted">Not now</button>
  </div>
</div>
```

**`settings.html.twig`** — per-group notification mute toggles.

**`groups/show.html.twig`** — optional bell icon in group header.

---

## Phase 5 — Subscription Cleanup

### Stale subscription removal

In `SendChatPushNotificationHandler`, after flushing the batch:
```php
foreach ($webPush->flush() as $report) {
    if ($report->isSubscriptionExpired()) {
        $this->subscriptionRepo->deleteByEndpoint($report->getEndpoint());
    }
}
```

### Optional cron: `app:chat:cleanup-push-subscriptions`

Periodic sweep of subscriptions with `expires_at < now()`. Low priority — the handler cleanup covers most cases.

---

## Privacy Model

| Aspect | Decision |
|--------|----------|
| Push payload content | Group name + sender display name only. No message text. |
| Notification display | "{senderDisplayName} sent a message" in {groupName} |
| Subscription storage | Endpoint + keys stored per user — standard Web Push requirement |
| VAPID subject | `mailto:` address identifying the operator |
| Operator visibility | Operator can see who has subscriptions (consistent with chat.md: not operator-blind) |

---

## Throttling

To prevent push spam in fast conversations:

- Redis key: `chat_push_cooldown:{groupId}` with 30-second TTL
- Handler checks: if key exists, skip sending
- After sending, set the key
- Effect: at most one push per group per 30 seconds

---

## File Map

### New files

```
src/ChatBundle/
  Entity/ChatPushSubscription.php
  Repository/ChatPushSubscriptionRepository.php
  Message/SendChatPushNotificationMessage.php
  MessageHandler/SendChatPushNotificationHandler.php
  Service/ChatWebPushService.php
  Controller/ChatPushController.php

assets/controllers/chat/
  chat_push_controller.js

public/
  chat-sw.js

migrations/
  VersionYYYYMMDDHHMMSS.php

documentation/
  chat-push-notifications.md  (this file)
```

### Modified files

```
src/ChatBundle/Entity/ChatGroupMembership.php          # +mutedNotifications field
src/ChatBundle/Service/ChatMessageService.php           # dispatch push after Mercure
src/ChatBundle/Controller/ChatSettingsController.php    # mute toggle endpoint
src/ChatBundle/Resources/config/routes.yaml             # push routes
src/ChatBundle/Resources/config/services.yaml           # VAPID parameter bindings
src/ChatBundle/Resources/views/layout.html.twig         # push prompt banner
src/ChatBundle/Resources/views/settings.html.twig       # mute toggles
src/ChatBundle/Resources/views/groups/show.html.twig    # optional bell icon
src/ChatBundle/DependencyInjection/ChatExtension.php    # VAPID config loading
src/ChatBundle/DependencyInjection/Configuration.php    # VAPID config schema
config/packages/chat.yaml                               # VAPID env wiring
config/packages/messenger.yaml                          # route push message
.env                                                    # VAPID env vars
CHANGELOG.md                                            # entry
```

---

## Dependencies

| Package | Purpose |
|---------|---------|
| `minishlink/web-push` | PHP Web Push library — VAPID signing, payload encryption, push delivery |

No JS packages needed — the Push API and Service Worker API are browser-native.

---

## Estimation

| Phase | Scope | Effort |
|-------|-------|--------|
| 1 — Infrastructure | Composer install, VAPID keys, config wiring | Small |
| 2 — Entity | 1 new entity + 1 field on existing, migration | Small |
| 3 — Backend | 1 service, 1 message, 1 handler, 1 controller, settings extension | Medium |
| 4 — Frontend | Service Worker, Stimulus controller, template changes | Medium |
| 5 — Cleanup | Handler cleanup + optional cron | Small |

