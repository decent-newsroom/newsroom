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

## Reading Nook publication-index TOCs

Reading Nook root and nested kind `30040` TOCs now offer a separate lazy
chapter preview for an available kind `30041` occurrence. Exact locally stored
pins may expose the preview immediately; an unpinned chapter must first become
available through the existing explicit TOC discovery action. Normal TOC
rendering does not resolve or fetch unpinned chapter references.

The preview endpoint is a private, `no-store` GET fragment. It re-reads and
reauthorizes the exact root index and every bounded nested-index source on each
request, checks the signed nested traversal context, and derives the target
only from the selected raw source-tag position. It accepts only a matching
kind `30041` coordinate and exact version pin when present. The target's hidden
coordinate, reader mute, author suppression, and NIP-09 tombstone policies are
checked before its body or metadata is returned. Missing, changed, or blocked
targets return the same not-found response.

The server signs the available target ID, coordinate, source path, and exact
occurrence into the lazy URL; a hand-built URL cannot bypass the explicit
discovery boundary for an unpinned chapter. This signature is only proof of the
displayed local resolution, not an authorization grant. Source and target
policy is still rechecked on every fragment.

The fragment renders only that chapter's tag-derived title/summary and body.
AsciiDoc uses the leaf converter, so body references are not recursively
expanded into previews. A conversion failure falls back to escaped plain text
without dropping the chapter identity or metadata. Sensitive-tag evidence
continues to gate title/summary metadata and is identified in the explicitly
opened preview.

This behavior is limited to Reading Nook's existing publication-index TOCs.
It does not mount Bookshelf, add general event discovery, fetch from the
network, use Article projections, or add recursive chapter expansion.

Guardrail: kind `30041` must never be wrapped as an Article entity or routed to `/article/...`; article routes remain only for long-form article kinds such as `30023`.
