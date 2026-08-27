# Ticket 01 — Publication index (kind 30040): chapter (kind 30041) loading is broken

**Severity:** High — core reading flow for publications is non-functional.
**Area:** Publications / NKBIP-01 (kind 30040 index, kind 30041 content sections)

## Problem statement

Opening a publication index (kind 30040) that references kind 30041 chapters never shows chapter
content. All chapters render as fallback placeholder cards with a "Fetch Chapter" CTA, and clicking
the CTA does not result in a loaded chapter. Chapters are never ingested, never persisted, and the
fetch CTA path is broken end-to-end.

## Desired behavior

1. Open a 30040 index → see the table of contents.
2. Select a chapter → chapter content loads from the local DB.
3. If the chapter is not in the DB, an **async** relay fetch is dispatched and the chapter content
   is pushed into the page via **Mercure** as soon as it arrives (same UX pattern as the existing
   `/e/{nevent}` loading page — see `templates/event/loading.html.twig` +
   `assets/controllers/content/event_fetch_controller.js` + `FetchEventFromRelaysHandler`).
4. No dead-end CTA, no `alert()`, no full-page `window.location.reload()`.

## Current implementation map

| Concern | Location |
|---|---|
| Magazine index page | `src/Controller/DefaultController.php` → `magIndex()` (~line 719), route `/mag/{mag}` |
| Chapters Turbo frame | `DefaultController::magChaptersFrame()` (~line 836), route `/mag/{mag}/chapters-frame`, cached 600s under key `magazine_chapters_frame_{mag}` |
| Single chapter page | `DefaultController::magChapter()` (~line 1009), route `/mag/{mag}/chapter/{slug}` (`magazine-chapter`) |
| Ebook view | `DefaultController::magRead()` (~line 949), route `/mag/{mag}/read` |
| Chapter resolution | `src/Service/Magazine/MagazineStructureService.php` → `resolveChapters()` (~line 194): parses `a` tags to coordinates `30041:pubkey:d-tag`, batch-looks-up via `EventRepository::findByCoordinates()` (~line 469), marks each entry `fetched: true/false` |
| Fallback card + CTA | `templates/magazine/_chapters_frame.html.twig` (~lines 40–60): placeholder card with `fetch-chapter-btn`, `onclick="fetchChapter(this)"` |
| CTA JS | Inline `<script>` in `templates/magazine/magazine-front.html.twig` (~lines 78–116) and `templates/magazine/read.html.twig` — `fetchChapter()` POSTs to `/api/fetch-chapter` |
| Fetch API | `src/Controller/Api/ChapterFetchController.php`, route `POST /api/fetch-chapter` — synchronous relay fetch via `NostrClient::getArticlesByCoordinates()` → `ArticleFetchService::fetchByCoordinates()`, persists an `Event`, invalidates frame cache |
| Ingestion workers | `src/Command/RunRelayWorkersCommand.php` (~lines 124–129) spawns `magazines:subscribe-local-relay` (`src/Command/SubscribeLocalMagazinesCommand.php`) |
| strfry router | `docker/strfry/router.conf` line 20 (`filter = {"kinds": [...]}`) |

## Root causes (confirmed)

### RC1 — kind 30041 is never ingested (primary)

- `docker/strfry/router.conf` line 20: the ingest filter contains `30040` but **not `30041`**, even
  though the comment on line 18 says it should ("30004, 30005, 30006, 30040, 30041 = curation and
  publications"). The local strfry relay therefore never receives 30041 events from upstream relays.
- `SubscribeLocalMagazinesCommand` defaults its `--kinds` option to `'30040'` only (line ~41), and
  `RunRelayWorkersCommand` spawns it **without** `--kinds=30040,30041` (lines ~124–129). So even
  events that do land on the local relay are never projected into the `event` table.
- `SubscribeLocalUserContextCommand::SUBSCRIBE_KINDS` (~lines 68–89) deliberately excludes 30040
  ("handled by the magazines worker") — but the magazines worker doesn't handle 30041, so 30041
  falls through every ingestion path.
- Net result: `EventRepository::findByCoordinates()` never finds chapters → every chapter is
  `fetched: false` → placeholder cards everywhere.

### RC2 — the "Fetch Chapter" CTA is broken

- The JS `fetchChapter()` sends only `{ coordinate }` in the POST body, but
  `ChapterFetchController` reads `mag` from the request to invalidate the
  `magazine_chapters_frame_{mag}` cache (line ~119, `invalidateMagazineChaptersCache($mag)`).
  **`mag` is never sent**, so even a successful fetch leaves the 600-second-stale cached frame in
  place; the follow-up `window.location.reload()` re-renders the stale placeholder cards. To the
  user, "nothing happened".
- `_chapters_frame.html.twig` includes `data-mag="{{ mag }}"` on the button, but the JS never puts
  it in the payload. The variant in `read.html.twig` doesn't even have `data-mag`.
- The synchronous fetch itself uses `ArticleFetchService::fetchByCoordinates()` (author relays via
  `RelaySetFactory::forAuthor()`, then default relays). If the author's NIP-65 list is unknown and
  the chapter only lives on the publication's home relay, the fetch legitimately fails — a relay
  hint mechanism / broader fallback is missing.

### RC3 — no async + Mercure path

- The desired UX (async fetch + Mercure push) already exists for `/e/{nevent}`
  (`FetchEventFromRelaysMessage` / `FetchEventFromRelaysHandler` publishes to topic
  `/event-fetch/{lookupKey}`, consumed by `assets/controllers/content/event_fetch_controller.js`),
  but the chapter flow predates it: it is synchronous, blocking, `alert()`-based, and
  reload-based.

## Implementation plan

### Phase 1 — Fix ingestion (makes the DB-first path work)

1. `docker/strfry/router.conf`: add `30041` to the `kinds` filter on line 20.
2. `src/Command/RunRelayWorkersCommand.php`: pass `--kinds=30040,30041` to the magazines worker
   (or change the default in `SubscribeLocalMagazinesCommand` to `'30040,30041'` — prefer changing
   the default so ad-hoc runs behave too).
3. Verify the projector used by the magazines worker persists 30041 through
   `GenericEventProjector` and that `Event::extractAndSetDTag()` populates `d_tag` (required by
   `EventRepository::findByCoordinates()` fast path).
4. Consider a one-shot backfill: for existing 30040 rows, collect all `a`-tag 30041 coordinates and
   dispatch fetch messages (can reuse the Phase 2 message in a loop from a console command, e.g.
   `magazines:backfill-chapters`). Optional but recommended so existing publications heal.

### Phase 2 — Replace sync CTA with async fetch + Mercure (skills: `create-async-message-handler`, `add-console-command` not needed)

1. New message `src/Message/FetchChapterMessage.php` (`coordinate` string, optional `relayHints`
   array, optional `mag` slug for cache invalidation) — or extend the existing
   `FetchEventFromRelaysMessage` with a coordinate/naddr mode if it already supports naddr lookups
   (check `FetchEventFromRelaysHandler` — it handles `naddr:{kind}:{pubkey}:{identifier}` lookup
   keys; reusing it is preferred to avoid a parallel pipeline).
2. Handler behavior (mirror `FetchEventFromRelaysHandler`):
   - Guard: if the event already exists in DB (`EventRepository::findByNaddr()`), publish
     `status=found` immediately and return.
   - Fetch via `NostrClient::getEventByNaddr()` (NOT `getArticlesByCoordinates()` — that method is
     longform-oriented). Include relay hints: author NIP-65 via `UserRelayListService`, plus any
     relay hint present in the 30040 index's `a` tag third element (check
     `MagazineStructureService::parseStructure()` — if the relay hint is currently discarded,
     preserve it and thread it through).
   - Persist via `GenericEventProjector::projectEventFromNostrEvent()`.
   - Invalidate `magazine_chapters_frame_{mag}` and `chapter_{eventId}` cache keys.
   - Publish Mercure update on topic `/event-fetch/naddr:30041:{pubkey}:{identifier}` (reuse the
     existing topic convention) with `status: found|not_found`.
3. Rewrite `ChapterFetchController::fetchChapter()` to: validate coordinate → dispatch message →
   return `202 { queued: true }`. Keep the route for backward compatibility, or drop it if the new
   frontend no longer calls it.

### Phase 3 — Frontend: table of contents + chapter loading UX

1. Replace the inline `fetchChapter()` scripts in `magazine-front.html.twig` and `read.html.twig`
   with a proper Stimulus controller in `assets/controllers/content/` (per repo convention — no
   inline JS; see skill `create-stimulus-controller`). Suggested name:
   `content--chapter-fetch` (`assets/controllers/content/chapter_fetch_controller.js`).
2. Controller behavior on placeholder cards: on click (or automatically on connect for the
   single-chapter page), POST to the fetch endpoint, then subscribe to the Mercure topic for the
   chapter's lookup key; on `status=found`, reload only the Turbo frame
   (`/mag/{mag}/chapters-frame`) or the chapter content frame — not the whole page. On
   `status=not_found`, swap CTA into an inert "not found on relays" state. Model this directly on
   `event_fetch_controller.js` (Mercure subscribe, slow-notice, timeout fallback).
3. `magChapter()` (single chapter route): when the chapter is missing from DB, render a loading
   template (analogous to `templates/event/loading.html.twig`) that dispatches the async fetch and
   subscribes to Mercure, instead of 404/fallback.
4. Ensure `mag` (and coordinate) are part of the POST payload so cache invalidation works.

### Phase 4 — Cache correctness

- `magChaptersFrame()` caches the rendered frame for 600s including placeholder state. After the
  handler persists a chapter it must invalidate `magazine_chapters_frame_{mag}`. Since the handler
  only knows the coordinate, either (a) pass `mag` in the message, or (b) invalidate by looking up
  all 30040s that reference the coordinate (a-tag containment query). Option (a) is simpler; do
  that, but note multiple magazines can share a chapter — acceptable staleness for others.
- Consider lowering the frame TTL when any chapter is `fetched: false` (e.g. 60s) so healed
  chapters appear without manual invalidation.

## Guardrails

- Do not add 30041 to `SubscribeLocalUserContextCommand::SUBSCRIBE_KINDS` — keep it in the
  magazines worker to preserve the documented worker split.
- Async fetch must be dispatched to the `async` transport (content fetches lane).
- Never block the HTTP request on a relay fetch in this flow (the 2–3s sync fetch pattern from
  `EventController` is acceptable as a fast-path *only if* followed by the async fallback; simplest
  correct version is DB-only + async).
- Drop-silently rules: run incoming 30041s through the same ingestion gates as other kinds
  (deletion service / ban checks in `GenericEventProjector`).

## Acceptance criteria

- [ ] With docker services rebuilt (`router.conf` change requires strfry container restart), newly
      published 30041 events referenced by tracked 30040s appear in the `event` table with `d_tag`
      populated.
- [ ] Opening `/mag/{mag}` shows the ToC; chapters present in DB render as full cards linking to
      `/mag/{mag}/chapter/{slug}`.
- [ ] A chapter missing from DB shows a placeholder; triggering fetch dispatches an async message,
      and when the relay returns the event, the card/content updates via Mercure **without a full
      page reload**.
- [ ] If the chapter can't be found on any relay, the UI shows a terminal "not found" state (no
      spinner forever, no alert()).
- [ ] Frame cache is invalidated on successful fetch (verify placeholder does not persist for
      600s).
- [ ] `bin/phpunit` green; add unit tests for the new/updated handler (found, not-found,
      already-in-DB race) — see `tests/Unit/` conventions and `tests/NostrTestHelpers.php`.

## Testing notes

- Reproduce: find/publish a 30040 with `a` tags to 30041s not in the local DB; open `/mag/{slug}`.
- Verify ingestion: `docker compose exec php bin/console magazines:subscribe-local-relay --kinds=30040,30041 -vv`
  and watch for 30041 stats (the command already labels kind stats, ~lines 127–132).
- All commands run inside the container: `docker compose exec php ...`.

## Documentation & changelog

- Update/add `documentation/` entry for publication reading (chapter loading lifecycle:
  DB-first → async relay fetch → Mercure push).
- Add Changelog entry: "Fix: publication (30040) chapter loading — ingest kind 30041, async chapter
  fetch with Mercure updates, working fetch CTA."
