# Skill: Audit an ingestion path

Use this guide when an event or article is missing, duplicated, delayed, or attributed to the wrong fetch path, or when measuring whether a worker contributes unique content.

## Define the sample and outcome

Choose a specific event ID or coordinate, author pubkey, event kind, and time window. Define success as the intended persisted and visible result. For contribution audits, count **new, unique articles attributable to the path**, not dispatched messages or relay responses.

## Follow the event

Trace the entry point (local strfry subscription, gateway, relay query, author fetch, or cron) through Messenger transport, handler, projector, database event and article records, Redis views or search index, and UI query. Record relay selection, queue lane, timeouts, and deduplication keys.

At each boundary, capture evidence for received, rejected, already present, newly inserted, projected, indexed, and rendered outcomes. Check filters, deletion or ban gates, kind handling, ownership rules, stale caches, and retry or queue delays. Do not infer zero contribution from a counter until its increment path is verified.

## Compare pathways

For a proposed retirement, identify other paths that ingest the same author's content. Compare their relay sources and timing. A duplicate observed by the retiring path may still have been first inserted elsewhere. Attribute the first successful persistence using event IDs, timestamps, and logs or database records where available; call attribution uncertain when evidence is insufficient.

## Report findings

Provide a compact path diagram, sampled IDs and outcomes, unique insert counts for the chosen period, resource cost if measured, and remaining uncertainty. Keep user identifiers and private log data out of external decision tools unless separately authorized. Run diagnostic commands inside Docker.

## Checklist

- [ ] Sample IDs, kind, author, and period are explicit.
- [ ] Source, relay selection, queue, handler, persistence, and read path are traced.
- [ ] Duplicate, filtered, failed, delayed, and newly inserted outcomes are separated.
- [ ] Counts are tied to an actual increment or query, not a label alone.
- [ ] Evidence and uncertainty are documented before changing ingestion code.
