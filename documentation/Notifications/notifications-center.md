# Notification subscriptions: retained schema and proposal

Status: unimplemented application feature. The notification migrations remain in the repository, but the notification controllers, entities, repositories, subscription services, feed templates, and stream controller described by earlier implementation notes are absent.

This page preserves the design intent and schema history. It is not a runbook for an available notifications product.

## Retained schema

- Migration `Version20260423120000` defines `notification_subscription` and `notification`. Subscriptions identify a user and a source; notifications hold event metadata and read/seen state.
- Migration `Version20260423140000` defines `notification_pro_subscription` for a proposed paid subscription lifecycle.

See the [migrations directory](../../migrations/) for the complete schema. Existing migration files alone do not establish that these tables are deployed on an instance.

## Proposed behavior

The intended feed matches new longform articles (30023) and publication indexes (30040) against explicitly subscribed authors, publication coordinates, and NIP-51 sets. Overlapping subscriptions should produce one notification per user and event.

The design calls for private Mercure updates with per-user authorization, a feed page, subscription management, and unread/seen controls. Chapters, reactions, zaps, chat, implicit mention notifications, and email/push digests were outside this scope.

The Notifications Pro proposal would lift a free subscription cap and enable NIP-51 set sources. Its old prices, role checks, endpoints, and receipt/expiry commands are not implemented contracts and are not advertised as current features.

For the existing Mercure infrastructure, see [Mercure administration](../Admin/mercure-admin.md).
