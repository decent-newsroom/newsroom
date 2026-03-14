# Priority Queue Setup for Comments

## What Changed

✅ Created a **priority transport** separate from async
✅ Comments now go to `priority` queue (faster processing)
✅ Background jobs stay on `async` queue (won't block comments)

## Configuration Details

### Priority Transport
- **Stream**: `{env}:messenger:priority`
- **Max entries**: 10,000 (smaller, focused on comments)
- **Redeliver timeout**: 300s (5 min - faster retry)

### Async Transport
- **Stream**: `{env}:messenger:async`
- **Max entries**: 50,000 (larger for bulk operations)
- **Redeliver timeout**: 600s (10 min - allows slow operations)

## Running Workers

### Option 1: Single Worker for Both (Simple)
```bash
docker compose exec frankenphp php bin/console messenger:consume async priority -vv
```

This processes both queues, but priority messages are checked first.

### Option 2: Dedicated Workers (Recommended for Backlog)
```bash
# Terminal 1: Dedicated to priority (comments only)
docker compose exec frankenphp php bin/console messenger:consume priority -vv --memory-limit=256M --time-limit=3600

# Terminal 2: Main async worker
docker compose exec frankenphp php bin/console messenger:consume async -vv --memory-limit=256M --time-limit=3600
```

### Option 3: Scale with Docker Compose
Update your `compose.yaml` or `compose.prod.yaml`:

```yaml
services:
  worker:
    # Existing worker for async
    command:
      - bin/console
      - messenger:consume
      - async
      - -vv
      - --memory-limit=256M
      - --time-limit=3600
    # ... existing config

  worker-priority:
    <<: *frankenphp-base  # Or copy from worker
    command:
      - bin/console
      - messenger:consume
      - priority
      - -vv
      - --memory-limit=128M  # Comments are lighter
      - --time-limit=3600
    restart: unless-stopped
```

Then scale:
```bash
# 3 workers for async, 1 dedicated for priority
docker compose up -d --scale worker=3
```

## Monitoring

### Check Queue Stats
```bash
php bin/console messenger:stats
```

Output will show both transports:
```
async    | 1234 messages
priority | 5 messages
```

### Monitor Both Queues
```bash
# Check async queue
docker compose exec frankenphp php bin/monitor-queue.php

# For priority queue, edit the script to use 'priority' stream
```

## Benefits

1. **🚀 Fast comment processing** - No waiting behind profile updates
2. **📊 Better visibility** - See comment queue separately  
3. **⚖️ Load balancing** - Dedicate resources where needed
4. **🔧 Independent scaling** - Scale comment workers separately

## Expected Behavior

### Before
```
Queue: [ProfileUpdate] [ProfileUpdate] [Comment] [ProfileUpdate] ...
       ↑ Comment stuck behind slow operations
```

### After
```
Priority Queue: [Comment] [Comment] → Fast processing
Async Queue:    [ProfileUpdate] [ProfileUpdate] → Bulk processing
```

## Next Steps

1. **Restart workers** to apply config changes
   ```bash
   docker compose restart worker
   ```

2. **Check both queues** are created
   ```bash
   php bin/console messenger:stats
   ```

3. **Monitor processing** - Comments should process faster
   ```bash
   docker compose logs -f worker
   ```

4. **Optional**: Add dedicated priority worker if needed

## Troubleshooting

### Comments still slow?
- Check if `FetchCommentsMessage` is being dispatched to correct transport
- Monitor `priority` queue: `redis-cli XLEN prod:messenger:priority`
- Verify routing in logs: Look for "Sending message to transport: priority"

### Both queues have backlog?
- Scale workers: `docker compose up -d --scale worker=5`
- Add dedicated priority worker (see Option 3 above)
- Check for handler errors in logs

## Redis Commands (if needed)

```bash
# Connect to Redis
docker compose exec redis redis-cli -a $REDIS_PASSWORD

# Check priority queue length
XLEN prod:messenger:priority

# Check async queue length  
XLEN prod:messenger:async

# View pending messages in priority
XPENDING prod:messenger:priority prod

# Clear priority queue (emergency only!)
DEL prod:messenger:priority
```
