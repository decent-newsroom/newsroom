# Cron Processing

## Overview

Background jobs run via the `cron` Docker service. Shell scripts in `docker/cron/` wrap console commands; the schedule is defined in `docker/cron/crontab`.

## Schedule

| Schedule | Script | Command | Purpose |
|----------|--------|---------|---------|
| `*/5 min` | `post_process_articles.sh` | `articles:post-process` | QA, indexing, mark as indexed |
| `*/15 min` | — | `app:cache_latest_articles` | Rebuild Redis article cache |
| `*/15 min` | — | `app:fetch-highlights --limit=100` | Fetch highlights from relays |
| `*/30 min` | — | `app:cache-latest-highlights` | Rebuild Redis highlights cache |
| `*/10 min` | `project_magazines.sh` | `app:project-magazines` | Project magazine indices to entities |
| `*/30 min` | `unfold_cache_warm.sh` | — | Warm Unfold subdomain caches |
| `0 */6 * * *` | `media_discovery.sh` | — | Cache media discovery events |
| `0 2 * * *` | `index_articles.sh` | — | Backfill historical articles |

## Adding a New Cron Job

1. Create a shell script in `docker/cron/` (make it executable)
2. Add the schedule line to `docker/cron/crontab`
3. Rebuild the cron container: `docker compose build cron`

## Environment

Cron jobs run with `APP_ENV=prod`. Environment variables are exported to `/etc/environment` at container startup. Logs go to `/var/log/cron-*.log` inside the container.

