# Bulk Event Deletion

Use this page when removing a large amount of already-ingested content. It is for operator cleanup, not normal NIP-09 author deletion handling.

## Commands

### Delete One Pubkey

```bash
docker compose exec php bin/console admin:delete-pubkey-events <hex-or-npub> --dry-run
docker compose exec php bin/console admin:delete-pubkey-events <hex-or-npub> --confirm
```

Options:

- `--dry-run` previews counts.
- `--exclude-kinds=0,3,10002` preserves selected event kinds.
- `--confirm` skips the interactive prompt.

This command bulk-deletes from projected tables and the `event` table, so it is much faster than replaying individual NIP-09 delete events for spam or abuse cleanup.

### Delete Muted Authors

```bash
docker compose exec php bin/console admin:delete-muted-events --dry-run
docker compose exec php bin/console admin:delete-muted-events --confirm
```

Use this after applying `ROLE_MUTED` when you want stored content from muted users removed locally.

### Replay NIP-09 Deletion Requests

```bash
docker compose exec php bin/console events:replay-deletions --dry-run
docker compose exec php bin/console events:replay-deletions --pubkey=<hex>
```

Use this when honoring author-signed delete events. It preserves the NIP-09 semantics and tombstone behavior, but it is slower for large operator cleanup.

## Relay-Side Cleanup

PostgreSQL deletion does not shrink the local strfry database. For relay disk cleanup, remove events from strfry and compact its storage separately during maintenance.

## Safety Checklist

1. Run the command with `--dry-run`.
2. Confirm the pubkey and excluded kinds.
3. Back up production data before large deletes.
4. Run during low traffic if the target has many events.
5. Rebuild or audit affected Redis/graph projections if the command output recommends it.

## Choosing A Path

| Goal | Use |
|---|---|
| Honor author deletion events | `events:replay-deletions` |
| Remove all local content from one abusive pubkey | `admin:delete-pubkey-events` |
| Remove content from all muted users | `admin:delete-muted-events` |
| Shrink relay disk usage | strfry database maintenance |