# Strfry Storage Maintenance

The local relay uses LMDB, configured by `docker/strfry/strfry.conf` at `/var/lib/strfry` and persisted in the `strfry_data` Docker volume. SQLite commands, `VACUUM`, and `/data/strfry.db` do not apply to this deployment.

## Inspect and back up

The repository's Makefile exposes the relay's native tools. Equivalent commands are:

```bash
docker compose exec strfry strfry db-stats
docker compose exec strfry du -sh /var/lib/strfry
docker compose exec -T strfry strfry export > relay-backup.jsonl
```

The export is JSONL and the redirect writes a backup on the host. Check the backup before maintenance. To restore an intentional export into the relay, the Makefile's `relay-import` target streams JSONL to `strfry import`.

## Cleanup boundaries

[Bulk Event Deletion](../Admin/deletion-optimization.md) removes projected application data from PostgreSQL. It does not delete relay events or compact LMDB. Conversely, removing events from the relay does not remove application projections.

Use the installed strfry release's native deletion and LMDB maintenance procedures when reclaiming relay storage; verify its available commands and options before a maintenance window. Event deletion and filesystem space reclamation are separate operations. Do not delete a guessed database file or run SQLite repair commands against the volume.

The write policy controls admission of incoming events. Rejecting old incoming events does not prune events already stored. Current age and size limits are in `docker/strfry/strfry.conf`; the `dbParams.dbsize` setting is LMDB's map capacity, not a retention policy.

Local hydration workers read from the relay into PostgreSQL. Starting `app:run-relay-workers` does not restore a lost relay database from PostgreSQL. Preserve an export or volume backup for recovery.

See [Relay Setup](relay-setup.md) for the service, volume, and configuration layout.
