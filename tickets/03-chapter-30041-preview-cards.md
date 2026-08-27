# Ticket 03 — Preview cards for kind 30041 (chapter) references in articles & highlights

**Severity:** Medium — feature gap; 30041 references currently degrade to a generic event card.
**Area:** Content rendering / Nostr reference embeds

## Problem statement

Articles (30023) and highlights (9802) can contain `nostr:naddr1...` references. References to
articles (kind 30023) already render as rich preview cards. References to publication content
sections / chapters (kind 30041) do not — they fall through to the generic `event_card.html.twig`
with no chapter semantics, or to a placeholder. We want a chapter preview card analogous to the
article preview.

**Hard constraint from the reporter:** do **not** link 30041 previews to article routes. Use the
chapter routes. The existing single-chapter route is `magazine-chapter`
(`/mag/{mag}/chapter/{slug}`, `DefaultController::magChapter()` ~line 1009) — note it requires a
magazine (`mag`) context that a bare naddr does not carry (see "Routing decision" below).

## How article previews work today (the pattern to replicate)

Pipeline in `src/Util/CommonMark/Converter.php`:

1. `convertToHTML()` (~line 70) → `normalizeBareNostrEntities()` (~line 74) converts bare bech32 to
   `nostr:` URIs → `prefetchNostrData()` (~line 88) batch-fetches referenced entities.
2. Post-HTML: `processNostrLinks()` (~line 927) collects all `nostr:` URIs (regex `RE_ALL_NOSTR`,
   ~line 40), decodes, and resolves:
   - by id → `EventRepository::findByIds()`
   - by naddr coordinate `kind:pubkey:identifier` → `EventRepository::findByNaddr()` /
     `findByCoordinates()` (~lines 415, 469)
3. `renderNostrLink()` (~line 1239) — **the kind branch is here**:
   - ~line 1336: `$isLongform = (int) $obj->kind === (int) KindsEnum::LONGFORM->value;`
   - ~lines 1337–1339: link target `/article/{naddr}` if longform else `/e/{naddr}`
   - ~lines 1356–1373: longform + found → `ArticleFactory::createFromLongFormContentEvent()` →
     render `components/Molecules/Card.html.twig`
   - ~lines 1375–1380: **everything else → generic `components/event_card.html.twig`** ← 30041
     lands here today
4. Not found locally → `renderDeferredEmbed()` (~line 1544) emits
   `<div class="nostr-deferred-embed" data-nostr-bech="..." ...>`; resolved at render time by the
   `resolve_nostr_embeds` Twig filter (`src/Twig/NostrEmbedExtension.php` ~line 21 →
   `src/Twig/NostrEmbedRuntime.php` ~line 52) which delegates to the
   `Molecules:NostrEmbed` Live-ish component (`src/Twig/Components/Molecules/NostrEmbed.php`):
   - `resolveNaddr()` (~lines 128–188), kind check at ~line 132
     (`$this->isLongform = ((int) $obj->kind === KindsEnum::LONGFORM->value);`)
   - template `templates/components/Molecules/NostrEmbed.html.twig` branches: `Molecules:Card` for
     articles, `kind20_picture`, `event_card`, placeholder.
5. Standalone coordinate embeds elsewhere use
   `Organisms/ArticleFromCoordinate` (`src/Twig/Components/Organisms/ArticleFromCoordinate.php` +
   `templates/components/Organisms/ArticleFromCoordinate.html.twig`) → renders `Molecules:Card` or
   `Molecules:CardPlaceholder` (which has a "Fetch" CTA).
6. Highlight (9802) source references: `HighlightController` (~lines 62–75) accepts only
   30023/30024 `a`-tag targets as article sources and renders via `ArticleFromCoordinate` → `Card`.
   In-content `nostr:` URIs inside highlight text go through the same Converter pipeline above.

## Existing chapter assets (state today)

- **`src/Twig/Components/Molecules/ChapterCard.php` exists but has NO template** —
  `templates/components/Molecules/ChapterCard.html.twig` does not exist anywhere under
  `templates/`. Rendering `Molecules:ChapterCard` today would throw. Props: `Event $chapter`,
  `string $mag`, `string $slug` (extracted from `d` tag in `mount()`). It also *requires* `mag`,
  which naddr references don't have.
- 30041 events are stored in the generic `Event` entity (no dedicated entity), content is AsciiDoc
  (NKBIP-01), title/summary come from `title` / `summary` tags. `d_tag` column enables coordinate
  lookup via `EventRepository::findByNaddr()` / `findByCoordinates()`.
- `KindsEnum::PUBLICATION_CONTENT` = 30041.
- Graph layer already maps 30041 → `'chapter'`
  (`RecordIdentityServiceTest` asserts `deriveEntityType(30041) === 'chapter'`).

## Routing decision (must resolve during implementation)

`magazine-chapter` requires `{mag}`. A bare `naddr` for a 30041 gives only
`kind:pubkey:identifier`. Options:

- **Option A (recommended): add a standalone chapter route**, e.g.
  `#[Route('/chapter/{naddr}', name: 'chapter', requirements: ['naddr' => '^naddr1.*'])]` in a
  Reader controller. It decodes the naddr, loads the event via `EventRepository::findByNaddr()`,
  renders the chapter (AsciiDoc → HTML via `Converter::convertAsciiDocToHTML()`, same as
  `magChapter()` ~line 1067), and — if a parent 30040 referencing this coordinate exists in the DB
  (JSONB `a`-tag containment query) — either redirects to `magazine-chapter` for full ToC context
  or renders with a "part of {publication}" breadcrumb. Falls back to async fetch + loading state
  when the event is missing (reuse the pipeline from Ticket 01/02).
- **Option B:** resolve the parent magazine at card-render time and link straight to
  `magazine-chapter`; if no parent found, fall back to `/e/{naddr}`. Cheaper but inconsistent
  targets and an extra query per card.

Option A gives a stable canonical URL for every 30041 and matches the reporter's "use chapters"
instruction. Implement A; B's parent-resolution can be an enhancement inside the chapter page
itself.

## Implementation plan

1. **ChapterCard component — make it usable without `mag`**
   - Rework `src/Twig/Components/Molecules/ChapterCard.php`: props `Event $chapter`, optional
     `?string $mag = null`; keep slug extraction (prefer the `d_tag` column over tag iteration);
     expose `title` (from `title` tag, fallback to `d` slug), `summary` (from `summary` tag,
     fallback to a truncated content excerpt), author pubkey, created-at, and the computed link
     (standalone `chapter` route when `mag` is null, `magazine-chapter` when `mag` provided).
   - Create `templates/components/Molecules/ChapterCard.html.twig`, modeled on
     `templates/components/Molecules/Card.html.twig` but chapter-flavored: a "Chapter" badge
     (translated — add keys to all 5 locale files, see skill `add-translations`), title, summary,
     `Molecules:UserFromNpub`-style author display consistent with Card, date. **Styles:** follow
     repo rules — no shading, no rounded edges; put any new CSS in `assets/styles/03-components/`,
     never inline.
2. **Standalone chapter route** (Option A)
   - New action (suggest `src/Controller/Reader/` per directory conventions; check
     `src/Controller/Reader/AGENT.md` first), route name `chapter`, path `/chapter/{naddr}`.
   - DB-first via `findByNaddr(30041, pubkey, identifier)`; render AsciiDoc content; missing →
     async fetch + Mercure loading state (reuse `FetchEventFromRelaysMessage` with lookupKey
     `naddr:30041:{pubkey}:{identifier}` — this dovetails with Tickets 01 & 02).
3. **Converter branch** — `Converter::renderNostrLink()` (~lines 1336–1380):
   - Add a chapter branch: `if ((int) $event->kind === KindsEnum::PUBLICATION_CONTENT->value)` →
     render `Molecules:ChapterCard` (embedded-controller render or direct
     `$this->twig->render('components/Molecules/ChapterCard.html.twig', [...])` consistent with how
     Card is rendered at ~line 1364).
   - Link-target logic (~lines 1337–1339): route 30041 naddrs to the new `chapter` route instead of
     `/e/{naddr}` (and never to `/article/...`).
   - Confirm `prefetchNostrData()` / `fetchEventsByNaddr()` already fetch 30041 coordinates from
     the DB (they're kind-agnostic via `findByNaddr` — verify, don't assume).
4. **NostrEmbed (deferred embeds)** — `src/Twig/Components/Molecules/NostrEmbed.php`
   `resolveNaddr()` (~line 132) and `templates/components/Molecules/NostrEmbed.html.twig`:
   - Add `isChapter` handling parallel to `isLongform`: DB lookup via `EventRepository`, set
     chapter payload, template renders `Molecules:ChapterCard`.
5. **Highlights** — `HighlightController` (~lines 62–75) currently whitelists 30023/30024 `a`-tag
   sources. Extend to accept `30041:` coordinates as highlight sources and render them with a
   `ChapterFromCoordinate`-style resolution (see step 6) instead of `ArticleFromCoordinate`.
   In-content references inside highlight text need no extra work once step 3 is done.
6. **Optional organism (if standalone coordinate embedding is needed)** —
   `Organisms/ChapterFromCoordinate` mirroring `ArticleFromCoordinate`: prop `coordinate`
   (`30041:pubkey:slug`), resolve via `EventRepository::findByNaddr()`, render
   `Molecules:ChapterCard` or `Molecules:CardPlaceholder`. Follow skill
   `create-twig-live-component`. Only build this if step 5 (highlights) needs it; the Converter
   path (steps 3–4) does not.

## Guardrails

- **Never** route 30041 to `/article/...` routes or wrap it in the `Article` entity /
  `ArticleFactory` (explicit reporter constraint).
- 30041 content is AsciiDoc — when excerpting for the card summary, strip AsciiDoc markup or use
  the `summary` tag only; do not run the full converter per card (perf).
- Keep DB-first resolution in components; no synchronous relay fetches during card render (missing
  chapters render as placeholder/deferred, async pipeline fills them — Ticket 01).
- Respect ingestion gates: previews render only what's in the DB.

## Acceptance criteria

- [ ] An article containing `nostr:naddr1...` pointing to a 30041 in the DB renders a chapter
      preview card (badge, title, summary, author, date) linking to the `chapter` route.
- [ ] The same works inside highlight content, and a 9802 whose `a` tag targets a 30041 shows the
      chapter card as its source.
- [ ] A 30041 reference NOT in the DB renders the deferred/placeholder state (no crash — note
      `ChapterCard` currently has no template; after this ticket it must).
- [ ] `/chapter/{naddr}` renders chapter content from DB (AsciiDoc converted), with async fetch
      fallback for missing events.
- [ ] 30023 article previews are unchanged (regression check).
- [ ] New translation keys exist in all 5 locale files (en, de, es, fr, sl).
- [ ] Unit tests: Converter kind-branching for 30041 (renders ChapterCard template, correct href);
      NostrEmbed resolveNaddr for a 30041; chapter route DB-hit and DB-miss paths.
- [ ] `docker compose exec php bin/phpunit` green; run
      `docker compose exec php bin/console asset-map:compile` after any CSS additions.

## Dependencies / sequencing

- Independent of Tickets 01–02 for the DB-hit rendering path.
- The async-fetch fallback for missing chapters should reuse the pipeline fixed in Ticket 01/02
  (`FetchEventFromRelaysMessage` + Mercure) — implement those first or stub the fallback as a
  simple placeholder until they land.

## Documentation & changelog

- Add `documentation/` entry (e.g. `documentation/chapter-previews.md`) covering: reference
  detection, kind branching, ChapterCard, the standalone `chapter` route, and the
  no-article-routes rule for 30041.
- Changelog entry: "Feature: preview cards for publication chapter (kind 30041) references in
  articles and highlights, with standalone /chapter/{naddr} route."
