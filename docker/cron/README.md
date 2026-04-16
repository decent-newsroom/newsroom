# Cron Container

This directory documents the scheduled task container defined in `compose.yaml` as service `cron`.

## How it works

- The `cron` service reuses the project PHP image.
- On startup it installs the crontab from `docker/cron/crontab`.
- Jobs execute shell scripts in `docker/cron/` and Symfony commands via `bin/console`.
- Environment variables needed by cron jobs are exported in the container entry command.

## Start and stop

From project root:

```bash
docker compose up -d cron
docker compose stop cron
```

## Update schedule or jobs

1. Edit `docker/cron/crontab` for schedule changes.
2. Edit the relevant script in `docker/cron/*.sh` for command changes.
3. Restart the cron service:

```bash
docker compose restart cron
```

## Debugging

Open a shell:

```bash
docker compose exec cron bash
```

Useful checks inside the container:

- `crontab -l` - confirm active schedule
- `ls -lah /var/log` - inspect available log files
- `tail -f /var/log/cron.log` - check cron daemon output (if present)

## Notes

- Use `docker compose` syntax (not legacy `docker-compose`).
- The source of truth for schedules is `docker/cron/crontab`.
- If `compose.yaml` cron entrypoint changes, keep this README in sync.
