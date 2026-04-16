# Kind 30040 Event Ingestion Setup

## Overview

Kind 30040 events (magazine indices / reading lists) are now automatically saved to the database as generic events when ingested by the strfry relay.

## Components Added

### 1. GenericEventProjector Service
**File**: `src/Service/GenericEventProjector.php`

A service that projects generic Nostr events into the Event entity. This handles:
- Saving events to the database
- Checking for duplicates
- Providing statistics by event kind

### 2. NostrRelayPool Enhancement
**File**: `src/Service/Nostr/NostrRelayPool.php`

Added `subscribeLocalGenericEvents()` method:
- Subscribes to local relay for specified event kinds
- Long-lived WebSocket connection
- Processes events via callback function
- Handles EOSE, reconnection, and error recovery

### 3. SubscribeLocalMagazinesCommand
**File**: `src/Command/SubscribeLocalMagazinesCommand.php`

A new command that subscribes to kind 30040 events:
```bash
php bin/console magazines:subscribe-local-relay
```

Options:
- `--kinds=30040,30041` - Subscribe to multiple kinds (default: 30040)

### 4. RunWorkersCommand Update
**File**: `src/Command/RunWorkersCommand.php`

The main worker command now includes the magazine hydration worker:
- Added `--without-magazines` option to disable it
- Automatically starts and monitors the magazine subscription worker
- Restarts on failure

## Configuration

### Strfry Router Config
The strfry router is already configured to ingest kind 30040 events:

**File**: `docker/strfry/router.conf`
```
filter = {"kinds":[30040,30023,30024,1111,9735,9802,0,5]}
```

Kind 30040 is already in the ingest stream pulling from:
- wss://nos.lol
- wss://relay.damus.io
- wss://theforest.nostr1.com

## Usage

### Running the Magazine Worker

#### Option 1: Standalone Command
```bash
php bin/console magazines:subscribe-local-relay
```

This will:
1. Connect to your local strfry relay
2. Subscribe to kind 30040 events
3. Save them to the Event table in real-time
4. Display progress in the console

#### Option 2: As Part of All Workers
```bash
php bin/console app:run-workers
```

This starts all background workers including:
- Article hydration (kind 30023)
- Media hydration (kinds 20, 21, 22)
- **Magazine hydration (kind 30040)** ← NEW
- Profile refresh
- Messenger consumer

To disable magazine worker:
```bash
php bin/console app:run-workers --without-magazines
```

### Custom Event Kinds

You can subscribe to additional event kinds:
```bash
php bin/console magazines:subscribe-local-relay --kinds=30040,30041,30078
```

## Database Schema

Events are saved to the `event` table with the following structure:

```sql
CREATE TABLE event (
    id VARCHAR(225) PRIMARY KEY,
    event_id VARCHAR(225),
    kind INT,
    pubkey VARCHAR(255),
    content TEXT,
    created_at BIGINT,
    tags JSON,
    sig VARCHAR(255)
);
```

## Querying Kind 30040 Events

The `MagazineProjector` service already queries kind 30040 events from the database:

```php
$repo = $this->em->getRepository(Event::class);
$events = $repo->findBy(['kind' => 30040], ['created_at' => 'DESC']);
```

Other services can also query these events:
```php
use App\Repository\EventRepository;

// Get all kind 30040 events
$events = $eventRepository->findBy(['kind' => 30040]);

// Get by pubkey
$events = $eventRepository->findBy([
    'kind' => 30040,
    'pubkey' => $pubkeyHex
]);
```

## Monitoring

### Check Event Stats
When you start the worker, it displays current statistics:
```
Current Database Stats
  Total events: 42
  - Magazines/Reading Lists: 42
```

### View Live Events
The worker displays events as they arrive:
```
[2026-01-24 10:30:45] Event received: abc123def456... (Magazine/List, pubkey: d475ce4b...) - d:my-magazine
[2026-01-24 10:30:45] ✓ Event saved to database
```

## Production Deployment

### Docker Compose
The workers should be run as a daemon process in production. Update your `compose.yaml` to include:

```yaml
services:
  workers:
    # ...existing worker config...
    command: php bin/console app:run-workers
```

This will automatically include the magazine worker.

### Systemd Service
If running outside Docker:

```ini
[Unit]
Description=Newsroom Magazine Hydration Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/app
ExecStart=/usr/bin/php /app/bin/console magazines:subscribe-local-relay
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

## Troubleshooting

### No Events Received
1. Check strfry relay is running:
   ```bash
   docker-compose ps strfry
   ```

2. Verify strfry router is syncing:
   ```bash
   docker-compose logs strfry-router
   ```

3. Check relay connection:
   ```bash
   php bin/console magazines:subscribe-local-relay
   # Should show "Subscribing to local relay: ws://strfry:7777"
   ```

### Events Not Saving
Check the worker logs for errors:
```bash
# If using Docker
docker-compose logs workers

# If running manually
php bin/console magazines:subscribe-local-relay -vv
```

### Duplicate Events
The `GenericEventProjector` automatically checks for duplicates by event ID before saving, so duplicate events are skipped.

## Testing

You can test the setup by publishing a kind 30040 event to your relay:

```bash
# Use a Nostr client or the nostur CLI to publish
# The worker should immediately detect and save it
```

Or check existing events:
```bash
php bin/console doctrine:query:dql "SELECT COUNT(e) FROM App\Entity\Event e WHERE e.kind = 30040"
```

## Related Commands

- `php bin/console app:project-magazines` - Project magazines from events to Magazine entities
- `php bin/console app:project-magazines <slug>` - Project a specific magazine
- `php bin/console app:cache-latest-articles` - Cache article data

## Notes

- Events are saved as-is with no validation beyond basic structure
- The MagazineProjector reads these events and creates Magazine entities
- Kind 30040 events are parameterized replaceable events (NIP-01), so newer events replace older ones by pubkey+d-tag
- The Event entity stores all event data including tags as JSON
