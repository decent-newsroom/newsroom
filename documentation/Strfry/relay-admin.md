# Relay Administration

## Overview

The relay admin interface at `/admin/relay` provides monitoring and management of the strfry relay and the relay pool.

## Features

- **Pool status**: Per-relay health scores, AUTH status, latency, last success/failure
- **Subscription worker heartbeats**: Monitor article, media, and magazine hydration workers
- **Gateway status**: Connection count, active subscriptions (when `RELAY_GATEWAY_ENABLED=true`)
- **Recent events**: Last events in the database with kind, ID, timestamp, content preview
- **Manual sync trigger**: Kick off a relay sync on demand

## Routes

| Route | Purpose |
|-------|---------|
| `/admin/relay` | Main dashboard |
| `/admin/relay/stats` | JSON stats endpoint |
| `/admin/relay/events` | JSON recent events |
| `/admin/relay/status` | JSON full status |

## Key Files

- `src/Controller/Administration/RelayAdminController.php`
- `src/Service/Admin/RelayAdminService.php`
- `templates/admin/relay/index.html.twig`

