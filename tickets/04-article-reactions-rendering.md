# Ticket 04 — Article pages: kind 7 reactions render as empty comment cards; render emojis, hearts for '+', like count in strip

**Severity:** Medium-high — visible broken UI on every article with reactions.
**Area:** Article page social layer (comments/zaps/reactions), NIP-25 / NIP-30

## Problem statement

On article pages, comments (kind 1111) and zaps (kind 9735) load from DB and update via Mercure —
that works. Reactions (kind 7) leak into the same list but render as **empty comment cards**: the
`.card.comment` wrapper with author + date appears, but no content is shown.

Reporter's desired behavior:
1. Reactions must render their emoji content properly in the comments/zaps feed.
2. Reactions whose content is `+` (NIP-25 default like) — and per NIP-25, empty content — render as
   a **heart**.
3. The **count of likes** should show in the article's reaction strip next to the heart — a slot is
   already allotted there (see below; it exists and is mostly wired — verify/fix rather than build).

## Root cause (verified against code)

### RC1 — unfiltered root condition leaks kind 7 into the comments list

`src/Repository/EventRepository.php` → `findCommentsByCoordinate()` (~lines 697–751). The outer
query is:

```sql
SELECT * FROM event e WHERE (
    {rootCondition}                                   -- ⚠️ NO kind filter
    OR (e.kind = 1111 AND e.id IN (SELECT id FROM tree))
    OR (e.kind = 9735 AND EXISTS (...))
)
```

`{rootCondition}` matches ANY event carrying an `a`/`A` (or `e`/`E`) tag equal to the article
coordinate — kind 7 reactions qualify (they tag the article with `['a', coordinate]`, see
`assets/controllers/ui/article_social_actions_controller.js` ~lines 101–111). Other kinds that
`a`-tag the article (e.g. 9802 highlights, reposts) can leak the same way.

### RC2 — reaction content is destroyed by the markdown converter

`templates/components/Organisms/Comments.html.twig` renders every list item's content through
`<twig:Atoms:Content content="{{ item.content }}" />` (line ~56).
`src/Twig/Components/Atoms/Content.php` runs it through
`Converter::convertToHTML()` (CommonMark). A lone `+` (or `-`) is a Markdown **bullet-list
marker**, producing an empty `<ul><li></li></ul>` — hence "wrapper rendered, no content". There is
no kind-7 branch in the template at all (no heart mapping, no NIP-30 custom-emoji resolution).

### RC3 — like count: slot exists and is wired, but definition of "like" is too narrow

- Slot: `templates/components/Molecules/ArticleSocialActions.html.twig` line ~51 —
  `<span class="article-social-actions__count" data-ui--article-social-actions-target="likeCount" hidden>`.
- JS: `assets/controllers/ui/article_social_actions_controller.js` — `fetchReactionState()`
  (~lines 56–77) GETs `/api/reactions/current` and `updateLikeUI()` (~lines 153–168) unhides the
  span when count > 0. This already works mechanically.
- API: `src/Controller/Api/ReactionController.php` → `current()` (~lines 30–75) counts
  `DISTINCT pubkey WHERE kind=7 AND content = '+' AND (a/A tag = coordinate)`.
  Per NIP-25 (`documentation/NIP/25.md`), **empty content also means like**, and in the wild many
  clients send `❤️`/`♥️`/`🤙`... The strict `content = '+'` filter undercounts, which is likely why
  the count appears missing. (Keep the `liked` check in sync with whatever definition is chosen.)

## Current implementation map

| Concern | Location |
|---|---|
| Article page comments frame | `templates/pages/article.html.twig` (~319–322) → `templates/pages/_article_comments_frame.html.twig` → `<twig:Organisms:Comments>` |
| Comments Live Component | `src/Twig/Components/Organisms/Comments.php` — `mount()` (~84–136) DB load via `findCommentsByCoordinate()`, maps entities to arrays (id, kind, pubkey, content, created_at, tags, sig); `loadComments()` LiveAction (~138–161) ingests the Mercure payload; helpers `parseZaps()`, `parseReplyMetadata()` |
| Comments template | `templates/components/Organisms/Comments.html.twig` — per-item card; kind-9735 branches at lines ~15, 47–55; content via `Atoms:Content` line ~56 |
| DB query | `src/Repository/EventRepository.php::findCommentsByCoordinate()` (~697–751) |
| Async refresh → Mercure | `Comments::mount()` dispatches `FetchCommentsMessage`; `src/MessageHandler/FetchCommentsHandler.php` re-queries via `findCommentsByCoordinate()` and publishes the payload consumed by `loadComments()` (via `assets/controllers/content/comments_mercure_controller.js` or similar — verify name) |
| Reaction strip (article level) | `templates/components/Molecules/ArticleSocialActions.html.twig` (like button ~41–52, count span ~51) + `assets/controllers/ui/article_social_actions_controller.js` |
| Reaction API | `src/Controller/Api/ReactionController.php` (`/api/reactions/current`, `/api/reactions/publish`) |
| Kind enum | `KindsEnum::REACTION` = 7 |
| Specs | `documentation/NIP/25.md` (reactions), `documentation/NIP/30.md` (custom emoji, `['emoji', shortcode, url]` tags) |

Note: reactions are ingested only via `ReactionController::publish()` →
`GenericEventProjector` (user-initiated) and via `SocialEventService::fetchArticleSocial()`
(`KindBundles::ARTICLE_SOCIAL` includes kind 7). No background worker subscribes to kind 7 —
out of scope for this ticket; enough reactions exist in the DB to reproduce.

## Implementation plan

### 1. Make the comments query kind-explicit (fixes the leak of arbitrary kinds)

In `findCommentsByCoordinate()`, constrain the root condition to an explicit kind whitelist and
include reactions deliberately:

```sql
WHERE (
    (e.kind IN (1111, 9735, 7) AND {rootCondition})
    OR (e.kind = 1111 AND e.id IN (SELECT id FROM tree))
    OR (e.kind = 9735 AND EXISTS (...))
)
```

This keeps reactions in the payload (needed for rendering them properly) while excluding
highlights/reposts/anything else that `a`-tags the article. Check callers of this method
(`Comments::mount()`, `FetchCommentsHandler`, possibly others — grep) to confirm none rely on the
leaky behavior.

### 2. Render reactions correctly in the Comments component

`src/Twig/Components/Organisms/Comments.php`:
- Add a `parseReactions()` helper (called from both `mount()` and `loadComments()`, like
  `parseZaps()`): split kind-7 items out of `$this->list` into a dedicated structure, e.g.
  `public array $reactions = []` — aggregated: normalized emoji → `['emoji' => string|null,
  'isLike' => bool, 'count' => int, 'custom' => ['shortcode' => ..., 'url' => ...]|null]`, counting
  **distinct pubkeys**. Normalization rules (NIP-25/NIP-30):
  - content `+` or `''` → like (heart)
  - content `-` → dislike; **do not render** (skip; simplest per reporter scope)
  - content `:shortcode:` with a matching `['emoji', shortcode, url]` tag → custom emoji (url)
  - anything else → literal emoji/string, render escaped as plain text (never through markdown)
- Keep kind 7 items OUT of the card list loop (filter them from `list` after splitting, so the
  per-item `{% for item in list %}` no longer sees them → no more empty cards).
- Make sure author metadata hydration still includes reaction pubkeys only if needed (aggregate
  display doesn't need per-author metadata; skip to avoid extra cache lookups).

`templates/components/Organisms/Comments.html.twig`:
- Add a compact **reaction strip** above the comments list (inside the `comments-list` wrapper or
  just before it): for each aggregated reaction render `emoji + count`; the like bucket renders a
  heart icon (`ux_icon('iconoir:heart', ...)` filled/`solar` variant consistent with
  `ArticleSocialActions`) + count. Custom emoji render as `<img src="{{ url }}" alt=":{{ shortcode }}:" class="reaction-emoji">`
  (escape everything; constrain size via CSS).
- New CSS goes in `assets/styles/03-components/` (e.g. `_comments.css` if it exists — check — else
  a new file). **No shading, no rounded edges** (repo style rules). No inline styles.
- Add translation keys for any labels/tooltips (e.g. "Likes") to ALL locale files
  `translations/messages.{en,de,es,fr,sl,it}.yaml` — note **it** exists too (6 locales; the ticket-03
  work already touched it).

### 3. Mercure parity

`FetchCommentsHandler` publishes the same query result; after step 1 the payload will contain
kind 7 items. `Comments::loadComments()` must run the same `parseReactions()` split so live
updates render identically to the initial server render. Verify the payload shape (the handler may
serialize entities differently — align keys with what `parseReactions()` expects).

### 4. Like count in the article reaction strip

`ReactionController::current()`:
- Broaden the like definition to `content IN ('+', '')` (NIP-25). Optionally also count common
  heart glyphs (`❤️`, `♥️`) — decide once and apply to BOTH the `count` query and the `liked`
  query so state stays consistent. Keep counting DISTINCT pubkeys.
- The JS/`likeCount` span already work — after the API broadening, verify end-to-end that a
  nonzero count unhides the span (no JS changes expected; `publishLike` optimistic bump at
  ~line 116 stays valid).

### 5. Do NOT do (scope guards)

- No background worker subscription for kind 7 (separate concern).
- No per-comment reactions (reactions targeting comment ids via `e` tags) — article-level only.
  The aggregation should simply ignore kind 7 items whose tags reference something other than the
  article root (defensive: the query only returns article-tagged ones anyway after step 1).
- No dislike UI.
- Do not run reaction content through `Atoms:Content`/CommonMark under any circumstances.

## Acceptance criteria

- [ ] Article with `+`/empty-content reactions: no empty comment cards; a heart + like count
      appears in the comments reaction strip; the `ArticleSocialActions` heart shows the same count.
- [ ] Article with emoji reactions (e.g. `🔥`) shows `🔥 × N` aggregated by distinct pubkeys.
- [ ] Custom emoji reaction (`:shortcode:` + `emoji` tag) renders the image, escaped, sized via CSS.
- [ ] `-` reactions render nothing and produce no empty wrapper.
- [ ] Highlights (9802) or other article-tagged kinds no longer appear in the comments list.
- [ ] Mercure update path renders reactions identically (test `loadComments()` with a payload
      containing kind 7 items).
- [ ] Comments and zaps rendering is unchanged (regression).
- [ ] `/api/reactions/current` counts `+` and empty-content likes; `liked` uses the same rule.
- [ ] Unit tests: `parseReactions()` aggregation (like normalization, custom emoji, distinct
      pubkeys, dislike skip); repository query kind whitelist (if there's an existing pattern for
      testing `findCommentsByCoordinate`, extend it — see
      `tests/Unit/Twig/Components/Organisms/CommentsTest.php`); `ReactionController::current()`
      like definition if testable without DB (else document manual check).
- [ ] `docker compose exec php bin/phpunit` targeted tests green (known pre-existing failure
      `NostrAuthenticatorSecurityTest::testReplayAttackProtection` — ignore);
      `lint:twig` on changed templates; `asset-map:compile` after CSS changes.

## Validation commands

```bash
docker compose exec php bin/phpunit --filter "Comments|Reaction"
docker compose exec php bin/console lint:twig templates/components/Organisms
docker compose exec php bin/console asset-map:compile
```

## Documentation & changelog

- Add/extend a `documentation/` entry for article social interactions (reactions rendering rules:
  like normalization, custom emoji, aggregation).
- Changelog (topmost in-development version, one item): "Fix: article reactions (kind 7) now
  render as emoji/heart aggregates instead of empty comment cards; like count shown in the
  reaction strip."
