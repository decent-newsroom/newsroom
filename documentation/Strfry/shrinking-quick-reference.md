# Strfry Shrinking & Maintenance Quick Reference

## Fastest Way to Shrink Strfry Storage

```bash
# 1. Check current size
docker compose exec strfry ls -lh /data/strfry.db

# 2. Connect to strfry database
docker compose exec strfry sqlite3 /data/strfry.db

# Inside sqlite3 shell (>) - PICK ONE:

# Option A: Delete old events (older than 30 days)
DELETE FROM events WHERE created_at < strftime('%s', 'now', '-30 days');

# Option B: Keep only last N events (e.g., 1 million)
DELETE FROM events WHERE rowid NOT IN (
  SELECT rowid FROM events ORDER BY created_at DESC LIMIT 1000000
);

# Option C: Delete by pubkey(s) (blocking spammers)
DELETE FROM events WHERE pubkey = x'<hex_pubkey_here>';

# 3. Reclaim disk space
VACUUM;
REINDEX;
PRAGMA optimize;

# 4. Exit
.exit

# 5. Verify new size
docker compose exec strfry ls -lh /data/strfry.db
```

## Combined PostgreSQL + Strfry Cleanup

For complete cleanup of a spammy pubkey across both databases:

```bash
# 1. Bulk delete from PostgreSQL
docker compose exec php bin/console admin:delete-pubkey-events \
  <npub_or_hex_pubkey> \
  --confirm

# 2. Delete from strfry (local relay)
docker compose exec strfry sqlite3 /data/strfry.db "
  DELETE FROM events WHERE pubkey = x'<hex_pubkey>';
  VACUUM;
"

# 3. Restart relay for good measure (optional)
docker compose restart strfry
```

## Typical Result

After cleanup:
```
Before: strfry.db = 2.5 GB
After:  strfry.db = 1.2 GB  (48% reduction)
Time:   ~2-5 minutes for compaction
```

## Check Strfry Storage Breakdown

```bash
docker compose exec strfry sqlite3 /data/strfry.db << 'EOF'
.mode line
SELECT 
  COUNT(*) as total_events,
  COUNT(DISTINCT pubkey) as unique_pubkeys,
  ROUND(SUM(LENGTH(content))/1024.0/1024.0, 2) as content_mb,
  MIN(created_at) as oldest_event,
  MAX(created_at) as newest_event
FROM events;
EOF
```

## Auto-Prune Strfry (via write-policy)

Edit `docker/strfry/write-policy.sh` to add auto-pruning:

```bash
# Add this line to periodically delete old events
if [[ "$kind" -ge 1 ]] && [[ "$createdAt" -lt $(date -d '30 days ago' +%s) ]]; then
  echo '{"action":"reject","msg":"Event too old"}'
  exit 0
fi
```

Then restart strfry:
```bash
docker compose restart strfry
```

## Monitoring Commands

```bash
# Event queue depth
docker compose exec strfry sqlite3 /data/strfry.db \
  "SELECT COUNT(*) as pending_events FROM events"

# Largest event kinds (by count)
docker compose exec strfry sqlite3 /data/strfry.db "
  SELECT kind, COUNT(*) as count 
  FROM events 
  GROUP BY kind 
  ORDER BY count DESC 
  LIMIT 10;
"

# Top pubkeys by event count
docker compose exec strfry sqlite3 /data/strfry.db "
  SELECT quote(pubkey) as pubkey_hex, COUNT(*) as count 
  FROM events 
  GROUP BY pubkey 
  ORDER BY count DESC 
  LIMIT 10;
"

# Database page stats
docker compose exec strfry sqlite3 /data/strfry.db "
  PRAGMA page_count;
  PRAGMA freelist_count;
"
```

## Schedule Regular Cleanup (Crontab)

Add to `docker/cron/crontab`:

```cron
# Shrink strfry every week
0 2 * * 0 docker compose exec strfry sqlite3 /data/strfry.db "DELETE FROM events WHERE created_at < strftime('%s', 'now', '-60 days'); VACUUM;" > /dev/null 2>&1
```

Rebuild cron container:
```bash
docker compose build --no-cache cron
docker compose up -d cron
```

## Emergency: Nuke & Restore Strfry

If strfry database is corrupted and you want a fresh start:

```bash
# WARNING: This deletes all local relay data

# 1. Stop the relay
docker compose stop strfry

# 2. Backup current database (just in case)
docker compose exec strfry cp /data/strfry.db /data/strfry.db.backup

# 3. Delete corrupted database
docker compose exec strfry rm /data/strfry.db

# 4. Restart strfry (it will create new database)
docker compose up -d strfry

# 5. Re-hydrate from PostgreSQL
docker compose exec php bin/console app:run-relay-workers
```

## Performance Tuning (Advanced)

Strfry's `router.conf` can be optimized:

```bash
# Check current settings
docker compose exec strfry cat /data/strfry.conf | grep -E "^max_|^db_"

# Edit if needed and restart
docker compose exec -T strfry /bin/sh -c "
  sed -i 's/^max_db_compressed_size=.*/max_db_compressed_size=2000000000/' /data/strfry.conf
"
docker compose restart strfry
```

