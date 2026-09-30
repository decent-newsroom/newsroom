# Chapter previews (kind 30041)

Publication chapter references use Nostr addressable events with kind `30041`. Markdown/AsciiDoc content is scanned for `nostr:naddr1…` references, prefetched from the local `event` table by `kind:pubkey:d` coordinates, and rendered DB-first.

Highlight source and coordinate cards render kind `30041` references with `Molecules:ChapterCard`, which shows a translated Chapter badge, title, summary/excerpt, author, date, and a chapter link. These cards look up missing local chapters in the Books API by exact coordinate and hydrate them without persisting. API results are cached briefly; card rendering never performs synchronous relay fetches.

Highlights and other addressable-reference cards use the same chapter-aware
path. A `30041` coordinate is rendered by `Organisms:ChapterFromCoordinate`
instead of the long-form `ArticleFromCoordinate` placeholder. Its fetch button
posts the coordinate and any relay hint from the reference to
`/api/fetch-chapter` when the local database and Books API both miss. A relay
result is stored by the worker, and the Mercure update refreshes the containing
feed or page.

Standalone chapter URLs live at `/chapter/{naddr}`. The controller decodes the naddr, requires kind `30041`, renders AsciiDoc content from a local row or transient Books API result, and shows a “part of” publication link when a local or Books API kind `30040` index references the chapter coordinate. Chapters missing from both sources dispatch `FetchEventFromRelaysMessage` and listen on `/event-fetch/{lookupKey}` through Mercure.

Guardrail: kind `30041` must never be wrapped as an Article entity or routed to `/article/...`; article routes remain only for long-form article kinds such as `30023`.
