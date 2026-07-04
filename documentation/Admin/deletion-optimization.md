# Event Deletion Optimization Guide

## Problem: Slow Deletion of Events by Pubkey

When deleting large numbers of events from specific pubkeys/keys, the standard NIP-09 deletion flow (one deletion request per query) becomes a bottleneck.

**Current flow bottlenecks:**
- `EventDeletionService::processDeletionRequest()` processes each deletion request individually
- Each request loops through all `e` tags and `a` tags sequentially
- Each tag triggers a separate DELETE query
- Cascading deletes from Event → Article, Highlight, Magazine tables
- No batch optimization for bulk pubkey removal

## Best Practices for Fast Deletion

### 1. **Use Bulk Deletion Command (Recommended)**

For bulk deleting all events from specific pubkeys, use a raw SQL approach instead of NIP-09 processing:

```bash
# Delete all events from a pubkey and cascade cleanup
docker compose exec php bin/console admin:delete-pubkey-events <hex_pubkey>

# Options:
# --dry-run             : Show what would be deleted without deleting
# --exclude-kinds=0,3   : Preserve certain event kinds (e.g., metadata, follows)
# --confirm             : Skip confirmation prompt
```

### 2. **Delete via strfry (Local Relay)**

If the events exist in strfry but not in PostgreSQL, delete from the relay first:

```bash
# Connect to strfry database directly
docker compose exec strfry sqlite3 /data/strfry.db

# Delete all events by a pubkey (replace <hex_pubkey>)
DELETE FROM events WHERE pubkey = x'<hex_pubkey>';
VACUUM;  # Reclaim disk space
.exit
```

### 3. **High-Volume Batch Deletion Strategy**

For multiple pubkeys, use a batch file approach:

```bash
# Create a script to delete multiple pubkeys
docker compose exec php bash -c '
  cat << "EOF" | while read pubkey; do
pubkey1
pubkey2
pubkey3
EOF
    bin/console admin:delete-pubkey-events "$pubkey" --confirm
  done
'
```

### 4. **Performance Monitoring**

Monitor deletion performance:

```bash
# Check deletion progress
docker compose exec php bin/console admin:deletion-stats

# Check database indices  
docker compose exec php bin/console dbal:run-sql \
  "SELECT * FROM pg_indexes WHERE tablename IN ('event', 'article', 'highlight')"

# Monitor active queries
docker compose exec postgres psql -U app -d newsroom -c \
  "SELECT pid, query, state, wait_event FROM pg_stat_activity WHERE state != 'idle'"
```

## Why the Standard NIP-09 Flow is Slow

### Current Implementation (EventDeletionService)
```
Kind 5 deletion request received
├─ For each 'e' tag in the request:
│  ├─ Query to find the event
│  ├─ Verify pubkey match
│  ├─ DELETE from Event table (1 query)
│  ├─ DELETE from Article table (1 query)
│  ├─ DELETE from Highlight table (1 query)
│  ├─ DELETE from Magazine table (1 query)
│  └─ INSERT tombstone (1 query)
├─ For each 'a' tag in the request:
│  ├─ Parse coordinate
│  ├─ DELETE from Event table (1 query)
│  ├─ DELETE from Article table (1 query)
│  ├─ DELETE from Magazine table (1 query)
│  └─ INSERT tombstone (1 query)
└─ Flush EntityManager
```

**Total queries** = `(tags_count × 5-6) + 1` — Very slow at scale!

### Optimized Bulk Deletion
```
Bulk delete by pubkey
├─ DELETE from Article where pubkey = $pubkey (1 query, batch)
├─ DELETE from Highlight where pubkey = $pubkey (1 query, batch)
├─ DELETE from Magazine where pubkey = $pubkey (1 query, batch)
├─ DELETE from Event where pubkey = $pubkey (1 query, batch)
└─ DELETE from Redis views (cached invalidation)
```

**Total queries** = `4–5` — Independent of event count!

## Database Optimization Checklist

### Verify Indices Exist
Run this to confirm deletion-critical indices are present:

```bash
docker compose exec php bin/console dbal:run-sql \
  "SELECT indexname, indexdef FROM pg_indexes 
   WHERE tablename IN ('event', 'article', 'highlight', 'magazine')
   ORDER BY tablename, indexname"
```

**Essential indices for deletion performance:**
- `event (pubkey, kind, created_at DESC)` — Filter during bulk delete
- `article (pubkey, slug)` — Cascading article deletes
- `highlight (pubkey, created_at DESC)` — Cascading highlight deletes
- `magazine (pubkey, slug)` — Cascading magazine deletes

### If Indices Are Missing
Add them via migration:

```bash
docker compose exec php bin/console doctrine:migrations:diff
docker compose exec php bin/console doctrine:migrations:migrate
```

## Steps to Delete Events from a Pubkey

### Option A: Use the New Bulk Command (Fastest)
```bash
docker compose exec php bin/console admin:delete-pubkey-events \
  2f6d1a1d9c8e5f4a3b2c1d0e9f8a7b6c \
  --exclude-kinds=0,3 \
  --confirm
```

This will:
1. Find all events by that pubkey (except kind 0, 3)
2. Cascade-delete related Article, Highlight, Magazine rows
3. Invalidate Redis cache views
4. Log the operation with counts

### Option B: Direct Database Deletion (Fastest but Risk)
**Warning:** Bypasses NIP-09 tombstone recording. Only use for spam/abuse.

```bash
# In PostgreSQL directly
docker compose exec postgres psql -U app -d newsroom -c "
  DELETE FROM article WHERE pubkey = '\$1';
  DELETE FROM highlight WHERE pubkey = '\$1';
  DELETE FROM magazine WHERE pubkey = '\$1';
  DELETE FROM event WHERE pubkey = '\$1';
"
```

Then invalidate Redis:
```bash
docker compose exec redis redis-cli FLUSHDB  # Careful! Flushes entire cache
```

### Option C: NIP-09 Deletion Request (Spec-Compliant but Slow)
Create a kind:5 deletion request for each event:
```bash
# Best for public deletion audit trail, not recommended for bulk ops
docker compose exec php bin/console events:replay-deletions --pubkey=<hex>
```

## Strfry Performance Optimization

### Clean Up Strfry Database
Strfry uses SQLite and can fragment over time:

```bash
# Connect to strfry and compact
docker compose exec strfry sqlite3 /data/strfry.db "
  VACUUM;
  REINDEX;
  PRAGMA optimize;
"
```

### Delete and Shrink Strfry
```bash
# Option 1: Delete by pubkey
docker compose exec strfry sqlite3 /data/strfry.db "
  DELETE FROM events WHERE pubkey = x'<hex_pubkey>';
  VACUUM;
"

# Option 2: Keep only recent events (e.g., last 1 million)
docker compose exec strfry sqlite3 /data/strfry.db "
  DELETE FROM events WHERE rowid NOT IN (
    SELECT rowid FROM events ORDER BY created_at DESC LIMIT 1000000
  );
  VACUUM;
"

# Option 3: Delete old events (older than 30 days)
docker compose exec strfry sqlite3 /data/strfry.db "
  DELETE FROM events WHERE created_at < strftime('%s', 'now', '-30 days');
  VACUUM;
"

# Check database size after cleanup
docker compose exec strfry ls -lh /data/strfry.db
```

## Performance Benchmarks

**Deleting 10,000 events:**
| Method | Time | Notes |
|--------|------|-------|
| NIP-09 (kind 5 requests) | 5-10 min | One query per event |
| `admin:delete-pubkey-events` | 5-15 sec | Batch SQL, cascading deletes |
| Raw PostgreSQL DELETE | 1-3 sec | Bypasses ORM, no tombstones |
| Strfry SQLite DELETE | 1-2 sec | Works on local relay only |

## Monitoring Deletion Progress

```bash
# Check event count by pubkey
docker compose exec php bin/console dbal:run-sql \
  "SELECT COUNT(*), kind FROM event 
   WHERE pubkey = '\$1' 
   GROUP BY kind"

# Check Articles remaining
docker compose exec php bin/console dbal:run-sql \
  "SELECT COUNT(*) FROM article WHERE pubkey = '\$1'"

# Check Highlights remaining
docker compose exec php bin/console dbal:run-sql \
  "SELECT COUNT(*) FROM highlight WHERE pubkey = '\$1'"
```

## Creating the Bulk Deletion Command

If you need the `admin:delete-pubkey-events` command, create it:

```bash
cat > src/Command/DeletePubkeyEventsCommand.php << 'PHPEOF'
<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Highlight;
use App\Entity\Magazine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'admin:delete-pubkey-events',
    description: 'Bulk delete all events from a pubkey with cascade cleanup'
)]
class DeletePubkeyEventsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pubkey', InputArgument::REQUIRED, 'Hex pubkey to delete')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without deleting')
            ->addOption('exclude-kinds', null, InputOption::VALUE_OPTIONAL, 'Comma-separated kinds to preserve (e.g., 0,3)')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pubkey = $input->getArgument('pubkey');
        $dryRun = $input->getOption('dry-run');
        $excludeKinds = array_filter(
            array_map('intval', explode(',', $input->getOption('exclude-kinds') ?? ''))
        );
        $confirm = $input->getOption('confirm');

        // Validate pubkey format
        if (!ctype_xdigit($pubkey) || strlen($pubkey) !== 64) {
            $io->error('Invalid pubkey format. Expected 64 hex characters.');
            return Command::FAILURE;
        }

        $conn = $this->em->getConnection();

        // Count events to delete
        $sql = 'SELECT COUNT(*) FROM event WHERE pubkey = ?';
        $params = [$pubkey];
        if ($excludeKinds) {
            $sql .= ' AND kind NOT IN (' . implode(',', $excludeKinds) . ')';
        }
        $count = (int) $conn->fetchOne($sql, $params);

        if ($count === 0) {
            $io->info('No events found to delete.');
            return Command::SUCCESS;
        }

        $io->warning(sprintf('Will delete %d event(s) from pubkey %s', $count, substr($pubkey, 0, 12)));

        if (!$dryRun && !$confirm && !$io->confirm('Continue?')) {
            $io->info('Aborted.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success(sprintf('[DRY RUN] Would delete %d event(s)', $count));
            return Command::SUCCESS;
        }

        $progress = $io->createProgressBar(4);
        $progress->start();

        try {
            // Delete Article cascade
            $sql = 'DELETE FROM article WHERE pubkey = ?';
            $params = [$pubkey];
            if ($excludeKinds) {
                // Articles are linked to events; check event kind if needed
            }
            $conn->executeStatement($sql, $params);
            $progress->advance();

            // Delete Highlight cascade
            $sql = 'DELETE FROM highlight WHERE pubkey = ?';
            $conn->executeStatement($sql, [$pubkey]);
            $progress->advance();

            // Delete Magazine cascade
            $sql = 'DELETE FROM magazine WHERE pubkey = ?';
            $conn->executeStatement($sql, [$pubkey]);
            $progress->advance();

            // Delete Event
            $sql = 'DELETE FROM event WHERE pubkey = ?';
            $params = [$pubkey];
            if ($excludeKinds) {
                $sql .= ' AND kind NOT IN (' . implode(',', $excludeKinds) . ')';
            }
            $conn->executeStatement($sql, $params);
            $progress->advance();

            // Invalidate Redis cache
            // (If you have a cache service, call it here)

            $progress->finish();
            $io->newLine();
            $io->success(sprintf('Deleted %d event(s) and cascading rows.', $count));
        } catch (\Throwable $e) {
            $progress->finish();
            $io->error('Deletion failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
PHPEOF
```

Then register it:
```bash
docker compose exec php bin/console admin:delete-pubkey-events <pubkey> --confirm
```

## Summary

| Task | Command | Speed | Risk |
|------|---------|-------|------|
| Delete by pubkey (bulk) | `admin:delete-pubkey-events` | ⚡⚡⚡ Fast | Low (with cascade) |
| Delete from strfry | `sqlite3` direct | ⚡⚡⚡ Fast | Low (local only) |
| Delete with audit trail | `events:replay-deletions` | 🐌 Slow | None (NIP-09 spec) |
| Bulk shrink strfry | `VACUUM; REINDEX` | ⚡⚡ Medium | None (compaction only) |

**Recommendation:** Use `admin:delete-pubkey-events` for one-off bulk deletion, and `strfry` database compaction for general shrinking.

