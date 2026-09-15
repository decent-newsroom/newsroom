# Chapter previews (kind 30041)

Publication chapter references use Nostr addressable events with kind `30041`. Markdown/AsciiDoc content is scanned for `nostr:naddr1…` references, prefetched from the local `event` table by `kind:pubkey:d` coordinates, and rendered DB-first.

Kind `30041` references render with `Molecules:ChapterCard`, which shows a translated Chapter badge, title, summary/excerpt, author, date, and a chapter link. Missing local data stays deferred so workers can fetch it asynchronously; card rendering never performs synchronous relay fetches.

Standalone chapter URLs live at `/chapter/{naddr}`. The controller decodes the naddr, requires kind `30041`, renders stored AsciiDoc content, and shows a “part of” publication link when a local kind `30040` index references the chapter coordinate. Missing chapters dispatch `FetchEventFromRelaysMessage` and listen on `/event-fetch/{lookupKey}` through Mercure.

Guardrail: kind `30041` must never be wrapped as an Article entity or routed to `/article/...`; article routes remain only for long-form article kinds such as `30023`.
