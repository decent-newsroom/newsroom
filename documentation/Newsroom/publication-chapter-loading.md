# Publication chapter loading

Publication indexes (`kind 30040`) reference chapter events (`kind 30041`) with `a` tags. Chapter rendering is DB-first: magazine pages resolve those coordinates from the local `event` table and render fetched chapters immediately.

When a referenced chapter is missing, the UI queues `POST /api/fetch-chapter` with the `30041:pubkey:d-tag` coordinate, magazine slug, and any relay hint from the index tag. The endpoint only dispatches `FetchEventFromRelaysMessage`; relay fetches run asynchronously on the `async` Messenger transport.

The worker fetches by naddr, persists through `GenericEventProjector`, invalidates `magazine_chapters_frame_{mag}` and `chapter_{eventId}`, then publishes `/event-fetch/{lookupKey}` through Mercure. The Stimulus chapter-fetch controller listens for that update and refreshes the affected Turbo frame or chapter page.

Existing publications can be healed with:

```bash
docker compose exec php bin/console magazines:backfill-chapters
```

Use `--dry-run` to preview missing chapter fetches before queueing them.
