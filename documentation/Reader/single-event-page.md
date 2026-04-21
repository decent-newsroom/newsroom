# Single Event Page Details

The generic event page at `/e/{nevent}` renders any Nostr event that is not
handled by a dedicated flow (articles, curation sets, chat). Historically the
template showed the title, byline and raw content only; a lot of protocol
information encoded in event tags was silently dropped.

This document describes the richer chrome added in v0.0.34.

## Kind 1111 (NIP-22 comments): parent + root cards

Comments are the most context-sensitive event kind: the content alone is
rarely enough to understand what the comment is about. The event page now
reads the NIP-22 scope tags and renders the referenced events as cards
around the comment body.

Tag conventions:

| Scope          | Addressable | Plain event | External identity |
|----------------|-------------|-------------|-------------------|
| Root           | `A`         | `E`         | `I`               |
| Direct parent  | `a`         | `e`         | `i`               |
| Kind hint      | `K` / `k`   | `K` / `k`   | —                 |
| Author hint    | `P` / `p`   | `P` / `p`   | —                 |

Render flow (see `templates/event/_kind1111_comment.html.twig`):

1. Extract all of the above from `event.tags` in a single pass.
2. Render a **"Replying to"** card (direct parent) above the comment body.
   - `a` → `<twig:Organisms:ArticleFromCoordinate>` (resolves against the
     article DB; falls back to a `CardPlaceholder` for non-article
     addressable kinds, which triggers a deferred fetch).
   - `e` → encode the hex id to `note1…` via the `nEncode` Twig filter and
     pass it to `<twig:Molecules:NostrEmbed type="note">`, which already
     handles longform, picture, and generic event cards, plus a
     client-side `nostr--deferred-embed` placeholder when the event is
     not in the local cache.
   - `i` → plain external link.
3. Render the comment content and gallery as before.
4. If the root is **different from the direct parent**, render a secondary
   **"In thread"** card below the content using the same resolution rules.
   If parent and root point to the same event the root card is suppressed
   to avoid showing two identical cards.

The kind hint (`k` / `K`) is displayed as a small badge beside the label,
so readers can immediately see whether they are looking at a reply to a
`kind:1` note, a `kind:30023` article, a `kind:20` picture, a `kind:11`
thread, etc.

## Generic event meta block

`templates/event/_event_meta.html.twig` is included below the content for
any kind that does not already have dedicated chrome (pictures 20, videos
21/22/34235/34236, tabular 1450, interests 10015, follow packs 39089 all
skip it). It surfaces:

- `summary` — rendered as a lede paragraph.
- `alt` — rendered prominently when `event.content` is empty or very
  short (covers kinds whose payload is purely tag-based); otherwise
  shown as a small "Description:" row in the extras list.
- `t` hashtags — linked to `forum_tag` so readers can jump to the
  corresponding topic page.
- `p` mentions — rendered through `<twig:Molecules:UserFromNpub>` with a
  link to the author profile; filtered to only include valid 64-hex
  pubkeys.
- `published_at` — formatted against the current locale.
- `client` — the client string as published.

The block auto-hides when none of these tags are present, so events with
minimal metadata still look clean.

## Style

Styles are in `assets/styles/03-components/event-single.css`, imported
from `assets/app.js`. The comment-parent card uses the same nostr-purple
left border as `.nostr-preview` to signal a referenced event; the
comment-root card is slightly muted. Following the project style rules,
no rounded corners or shadows are introduced (shadow-sm from the existing
card baseline is kept).

## Files touched

- `templates/event/index.html.twig` — dispatches kind 1111 to the new
  partial and includes the meta block.
- `templates/event/_kind1111_comment.html.twig` — new. Used on the
  single-event page where the comment itself is the focus.
- `templates/event/_event_meta.html.twig` — new.
- `templates/partial/_comment_as_card.html.twig` — new. Inverted
  layout used in feed contexts (bookmarks, expression results): the
  resolved root/parent target is the primary card, the comment text is
  shown as a small "X commented:" callout above it.
- `templates/partial/_bookmark_event_card.html.twig` — dispatches kind
  1111 through `_comment_as_card.html.twig` so a comment in a feed reads
  as the article being discussed, not a context-free comment body.
- `assets/styles/03-components/event-single.css` — new.
- `assets/app.js` — imports the new stylesheet.

## Two layouts, two contexts

The same NIP-22 comment is rendered differently depending on where it
appears:

| Context                         | Template                              | Headline             |
|---------------------------------|---------------------------------------|----------------------|
| `/e/{nevent}` single event page | `event/_kind1111_comment.html.twig`   | the comment          |
| Bookmarks                       | `partial/_comment_as_card.html.twig`  | the referenced item  |
| Expression results              | `partial/_comment_as_card.html.twig`  | the referenced item  |

In feed contexts the inversion matters: a `kind:30880` expression that
returns recent `kind:1111` events (e.g. "comments by my contacts") is
much more useful when the cards in the result list are the articles
those contacts commented on, rather than a stack of one-line replies
without context. The comment author + text are preserved as a small
callout so the conversational signal isn't lost.

