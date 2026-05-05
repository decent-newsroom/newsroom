# NIP-11 & NIP-66 Relay Discovery

> Written May 2026 — covers the relay intelligence stack added to Decent Newsroom.

---

## Overview

Two complementary relay meta-protocols have been implemented to improve relay selection, gateway preflight accuracy, and to seed the beginnings of a dynamic relay directory:

| Protocol | Purpose | Direction |
|----------|---------|-----------|
| **NIP-11** | Relay Information Document — HTTP GET, per-relay JSON | Client pulls |
| **NIP-66** | Relay Monitoring / Discovery events — Nostr kind 30166 | Monitor pushes via relay |

Together they replace the pure trial-and-error connection logic with data-driven decisions.

---

## NIP-11 (Relay Information Document)

### What we fetch

A simple HTTP GET to the relay's root URL with `Accept: application/nostr+json` returns a JSON document describing:

- `name`, `description`, `pubkey`, `contact`
- `software`, `version`
- `supported_nips` — the NIPs this relay implements
- `limitation` → `auth_required`, `default_limit`, `max_limit`, `retention`, etc.
- `fees`, `payments_url`, `posting_policy`

### Storage

| Layer | Key | TTL |
|-------|-----|-----|
| PostgreSQL `relay_information` table | `url` (PK, normalised) | permanent |
| Redis `relay_info:{url}` | hot fields: `auth_required`, `supported_nips` | 6 h |
| `RelayHealthStore` | `auth_required`, `supported_nips` fields in `relay_health:{url}` | 7 d |

### Gateway preflight

`RelayGatewayCommand::openConnection()` now runs a zero-network preflight before attempting a WebSocket handshake:

```
if NIP-11 cached for relay AND auth_required=true AND pubkey=null (anonymous open):
    raise AuthRequiredSkippedException  → skip silently, log at INFO
```

This avoids opening WebSocket connections to auth-gated relays when we have no signer — a common source of wasted connections and false failure counts in `RelayHealthStore`.

### Refresh cycle

- **Cron**: `app:relays:refresh-information --stale --async` runs every 6 hours.
  Dispatches `FetchRelayInformationMessage` per stale URL → `FetchRelayInformationHandler` → `RelayInformationFetcher`.
- **Admin "Refresh now"** button: same message dispatch, returns immediately.
- **CLI**: `bin/console app:relays:refresh-information --all` — inline sync refresh.

### Admin UI

`/admin/relay/nip11` — table of every known relay with name, software, NIP list, auth status, last fetch time, fetch errors. Per-row "Refresh" button.

---

## NIP-66 (Relay Discovery / Liveness Monitoring)

### Protocol

Relay monitoring bots publish two kinds of Nostr events:

| Kind | Name | Semantics |
|------|------|-----------|
| `10166` | Relay Monitor Announcement | Replaceable. The monitor advertises itself, its frequency, and which relays it watches. |
| `30166` | Relay Discovery | Parameterised replaceable (`d` = normalised relay URL). RTT measurements, accepted kinds, supported NIPs, auth/payment requirements. |

### Trust model

Only 30166 events from **trusted monitor pubkeys** are projected into `monitored_relay`. Untrusted monitors are silently dropped. Operators add trust via the admin UI (`/admin/relay/monitors`) or CLI.

Bootstrap pubkeys can be seeded via `services.yaml`:

```yaml
relay_discovery.bootstrap_monitor_pubkeys:
    - 'abc123…'
```

### Storage

| Table | Description |
|-------|-------------|
| `trusted_relay_monitor` | Operator-approved monitor pubkeys |
| `relay_monitor` | Kind 10166 announcements (one row per monitor pubkey) |
| `monitored_relay` | Kind 30166 observations (one row per monitor × relay URL) |

### Projection flow

```
Event arrives (kind 10166 or 30166)
  └─ GenericEventProjector::projectEventFromNostrEvent()
       └─ RelayDiscoveryEventProjector::onProjected()
            ├─ kind 10166 → RelayMonitorRepository::upsert()
            └─ kind 30166, trusted monitor?
                 ├─ yes → MonitoredRelayRepository::upsertObservation()
                 │         RelayHealthStore::recordMonitorObservation() (blends RTT into avg_latency_ms @ 0.1 weight)
                 └─ no  → drop silently
```

### RelayDirectoryService

`RelayDirectoryService` exposes query methods for the rest of the application:

```php
$directoryService->findRelaysSupportingKind(30023, limit: 5);   // NIP-66 ranked list
$directoryService->rank($candidateUrls);                         // sort by composite score
$directoryService->getDirectory(['kind' => 30023]);              // admin view
```

Composite ranking: 50% health score + 30% median RTT + 20% monitor consensus count.

### Admin UI

| Path | Description |
|------|-------------|
| `/admin/relay/directory` | Ranked NIP-66 relay directory with optional kind/NIP filter |
| `/admin/relay/monitors` | Trusted monitor management (add/remove pubkeys) |

---

## Files Added / Changed

### New
- `src/Entity/RelayInformation.php`
- `src/Repository/RelayInformationRepository.php`
- `src/Dto/Nostr/RelayInformationDocument.php`
- `src/ReadModel/RedisView/RedisRelayInfoView.php`
- `src/Service/Nostr/RelayInformationFetcher.php`
- `src/Message/FetchRelayInformationMessage.php`
- `src/MessageHandler/FetchRelayInformationHandler.php`
- `src/Exception/Nostr/AuthRequiredSkippedException.php`
- `src/Command/Relay/RefreshRelayInformationCommand.php`
- `src/Entity/RelayMonitor.php`
- `src/Entity/MonitoredRelay.php`
- `src/Entity/TrustedRelayMonitor.php`
- `src/Repository/RelayMonitorRepository.php`
- `src/Repository/MonitoredRelayRepository.php`
- `src/Repository/TrustedRelayMonitorRepository.php`
- `src/Service/Nostr/Projector/RelayDiscoveryEventProjector.php`
- `src/Service/Nostr/RelayDirectoryService.php`
- `migrations/Version20260505120000.php` (relay_information table)
- `migrations/Version20260505130000.php` (monitored_relay, relay_monitor, trusted_relay_monitor)
- `templates/admin/relay/nip11.html.twig`
- `templates/admin/relay/directory.html.twig`
- `templates/admin/relay/monitors.html.twig`

### Modified
- `src/Service/Nostr/RelayHealthStore.php` — `setSupportedNips()`, `getSupportedNips()`, `recordMonitorObservation()`
- `src/Service/Nostr/RelayGatewayCommand.php` — NIP-11 preflight in `openConnection()`
- `src/Service/Admin/RelayAdminService.php` — `getRelayInformationOverview()`, `getRelayDirectory()`, `getMonitors()`, monitor trust/untrust
- `src/Controller/Administration/RelayAdminController.php` — NIP-11, directory, monitors routes
- `src/Service/GenericEventProjector.php` — call `RelayDiscoveryEventProjector::onProjected()`
- `src/Enum/KindsEnum.php` — `RELAY_MONITOR_ANNOUNCEMENT = 10166`, `RELAY_DISCOVERY = 30166`
- `config/services.yaml` — new parameters + service wiring
- `config/packages/messenger.yaml` — route `FetchRelayInformationMessage` to `async_low_priority`
- `docker/cron/crontab` — 6-hourly NIP-11 refresh
- `templates/admin/relay/index.html.twig` — nav links to NIP-11 + NIP-66 pages

