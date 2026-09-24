# Cron Processing

The `cron` Docker service runs the schedule in `docker/cron/crontab`. Scripts in `docker/cron/` wrap longer console jobs; inline commands source `/etc/environment`. The checked-in crontab is the source of truth for timing and command flags.

## Schedule

| Schedule | Command or script | Purpose |
|---|---|---|
| Every 10 minutes | `post_process_articles.sh` | Article QA and indexing |
| Daily at 02:00 | `index_articles.sh` | Historical article backfill |
| Every 15 minutes | `app:cache-latest-articles` | Article view cache |
| Every 30 minutes | `app:fetch-highlights --limit=200` | Fetch highlights |
| Every 30 minutes | `app:cache-latest-highlights` | Highlight view cache |
| Every 6 hours | `media_discovery.sh` | Media discovery |
| Every 6 hours | `app:relays:refresh-information --stale --async` | Dispatch NIP-11 refresh |
| Every 30 minutes | `project_magazines.sh` | Magazine projection |
| Every 30 minutes | `unfold_cache_warm.sh` | Unfold site cache |
| Daily at 03:00 | `dn:graph:audit --fix` | Audit and repair graph consistency |
| Every 5 minutes | `updates-pro:check-receipts` | Updates Pro payment receipts |
| At minutes 0 and 59 each hour | `updates-pro:expire-subscriptions` | Expire Updates Pro subscriptions |
| Every 5 minutes | `vanity:check-receipts` | Vanity name payment checks |
| Daily at 01:00 | `vanity:process-expired` | Release expired vanity names |
| Every 5 minutes | `essayist:check-zap-receipts` | Essayist membership receipts |
| First day of each month at 00:05 | `essayist:expire-memberships` | Expire monthly memberships |
| Every 10 minutes | `app:cache-sitemap` | XML sitemap cache |

Times follow the cron container's clock. The `*/59` minute expression is minutes 0 and 59 of each hour, not a fixed 59-minute interval.

## Operation

Logs are written to `/var/log/cron-*.log` inside the container. Async dispatch jobs also require the matching [Messenger workers](../Processes/workers.md).

When adding a job, use a script for multi-step work, make it executable, and add one schedule entry. Rebuild and recreate the cron service so the installed crontab changes:

```bash
docker compose build cron
docker compose up -d cron
docker compose logs --tail=100 cron
```
