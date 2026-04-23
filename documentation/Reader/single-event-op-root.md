# Single Event OP Root (kind:1)

## What this adds

On the single event page (`/e/{nevent}`), kind:1 notes now show the thread root event as **OP** at the top when the note includes a NIP-10 `e` tag marked `root`.

## Resolution flow

1. Parse note tags and find an `e` tag with marker `root`.
2. Resolve the referenced event from the local database first.
3. If missing locally, do a synchronous relay lookup (`getEventById`) using relay hints from the root tag when available.
4. Persist fetched events through `GenericEventProjector` and render the OP card above the current note.

## Notes

- Only kind:1 events use this OP block.
- If the root id matches the current event id, the OP block is not shown.
- If the root event cannot be resolved, the page renders normally without the OP section.

