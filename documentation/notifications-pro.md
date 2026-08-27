# Notifications Pro

Notifications Pro is a paid tier that removes limitations on the Notifications Center.

## What it unlocks

| Feature | Free | Pro |
|---------|------|-----|
| Max active subscriptions | 5 | Unlimited |
| Sources: npub, publication | ✓ | ✓ |
| Source: NIP-51 interest sets | ✗ | ✓ |

## Payment flow

Notifications Pro uses the same Lightning / zap-receipt payment loop as **Active Indexing**:

1. User visits `/subscription/notifications-pro` and selects a tier (monthly / yearly).
2. Server creates BOLT11 invoice via `NotificationProService::createSubscriptionInvoice()`, persists a `notification_pro_subscription` row with `status=pending`.
3. User pays from a Lightning wallet.
4. Cron job `active-indexing:check-receipts` (runs every 5 min) scans `event` table for matching kind-9735 zap receipts and calls `activateSubscription()` / `renewSubscription()`.
5. Activation grants `ROLE_NOTIFICATIONS_PRO` to the user's `app_user` row.

## Subscription lifecycle

```
pending → active → grace → expired
```

- `active-indexing:check-receipts` handles `pending → active` / `pending → renew`.
- `notifications-pro:expire-subscriptions` handles `active → grace` and `grace → expired` plus role revocation.

Both commands run via cron (see `docker/cron/crontab`).

## Pricing

| Tier | Sats | Duration | Grace |
|------|------|----------|-------|
| monthly | 500 | 30 days | 7 days |
| yearly | 5000 | 365 days | 30 days |

Defined in `src/Enum/NotificationProTier.php`.

## Gating

Access checks live in `NotificationAccessService`:

```php
$accessService->canAddSubscription($user, $type); // bool
$accessService->blockReason($user, $type);         // ?string (translation key)
```

The controller (`NotificationsController::addSubscription`) calls `blockReason()` and redirects to the Pro landing page when blocked.

## Translation keys

Defined in `translations/messages.en.yaml`:

```yaml
notificationsPro:
    required:
        nip51Sets: '...'
        cap: '...'
```

## Routes

| Route | URL |
|-------|-----|
| `notifications_pro_index` | `/subscription/notifications-pro` |
| `notifications_pro_subscribe` | `/subscription/notifications-pro/subscribe/{tier}` |
| `notifications_pro_renew` | `/subscription/notifications-pro/renew/{tier}` |
| `notifications_pro_check_payment` | `/subscription/notifications-pro/check-payment/{id}` |
| `notifications_pro_cancel` | `/subscription/notifications-pro/cancel` |

## Database

Table: `notification_pro_subscription`

| Column | Type | Notes |
|--------|------|-------|
| id | int | PK |
| npub | varchar(255) | unique |
| tier | varchar(20) | enum: monthly/yearly |
| status | varchar(20) | enum: pending/active/grace/expired |
| started_at | timestamp | nullable |
| expires_at | timestamp | nullable |
| grace_ends_at | timestamp | nullable |
| pending_invoice_bolt11 | text | cleared on activation |
| zap_receipt_event_id | varchar(255) | event id of payment |
| created_at | timestamp | |
| updated_at | timestamp | |

Migration: `Version20260423140000`.

