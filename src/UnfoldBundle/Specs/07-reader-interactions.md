# Reader Interactions On Unfold

Status: draft implementation specification.

Unfold publication pages support the same reader interactions as the main
DN reader: likes (reactions), bookmarks, and highlights (view + create).

## Kinds

| Kind | Interaction | Signer | Existing enum |
|---|---|---|---|
| `7` | Reaction (like) | Reader | `KindsEnum` reactions |
| `10003` | Bookmark list | Reader | `KindsEnum` bookmarks |
| `9802` | Highlight | Reader | `KindsEnum` highlights |

No new kinds. All three are standard NIPs (25, 51, 84) and interoperate with
the rest of Nostr.

## Reuse Map (host app → bundle)

| Concern | Existing implementation | Reuse strategy |
|---|---|---|
| Publish reaction | `Api/ReactionController` + `PublishReactionMessage`/`PublishReactionHandler` | Expose on Unfold host; route through same handler |
| Reaction/bookmark UI | `ui/article_social_actions_controller.js`, `ui/card_bookmark_controller.js` | Ship Stimulus controllers into theme layout; bundle templates get the same data attributes |
| Bookmark list read/write | `Reader/BookmarksController`, `ui/bookmark_list_controller.js` | Client-side signing flow identical; list fetched from reader's relays |
| Highlight create | `nostr/nostr_highlight_controller.js` | Reuse; selection UI works on any article body |
| Highlight display | `Highlight` entity, `HighlightService`, `HighlightRepository`, `RefreshArticleHighlightsMessage` | Bundle queries highlights by article coordinate (DB-first, relay fallback) |
| Highlight caching | `RedisHighlightView`, `CacheLatestHighlightsCommand` | Per-article highlight sets cached via `StaleWhileRevalidateCache` |

The bundle must consume these through interfaces (extraction goal, Phase 7):
define `InteractionReaderInterface` / `InteractionPublisherInterface` in the
bundle with host-app adapters wired in `services.yaml`.

## Behavior

### Likes

- Like button on article pages; count + user's own state when logged in.
- Reaction event `e`/`a`-tags the article coordinate, `p`-tags the author.
- Publish to the reader's write relays **and** the publication home relay so
  other readers of the same site see the count.

### Bookmarks

- Bookmark toggle on article cards and article pages.
- Standard `kind:10003` read-modify-write of the reader's own list, signed in
  the browser. The list lives on the reader's relays, not the publication's.
- Warn-before-overwrite behavior must match the main app (replaceable-list
  race: fetch latest before re-signing).

### Highlights

- Existing highlights for the article render as text decorations plus a
  highlight margin/list, same data as the main reader.
- Logged-in readers can select text and publish a `kind:9802` with the
  article `a` coordinate, `p` author tag, and quoted content.
- Anonymous readers see highlights but get a login prompt on create.

## Gated Content Rules (interacts with Spec 06)

Highlights **quote article text**. For gated articles this is a paid-content
leak vector:

1. A highlight of a gated article MUST carry the article's `s` scope tag(s)
   and MUST be published only to the publication home relay — it goes through
   the same Phase 5 publishing chokepoint as gated articles themselves.
2. The gated relay applies the same token check to `9802` reads as to the
   articles (it already does, by the `s`-tag rule — no special casing).
3. Reactions (`kind:7`) carry no content; liking a gated article publicly is
   acceptable metadata leakage for v1 but the `e`/`a` tag reveals *what* was
   liked — document this to users; optionally route gated-article reactions
   to the home relay only.
4. Bookmarks of gated articles expose the coordinate in a public list; same
   v1 stance as reactions.

## Auth And Signing On Subdomains

- NIP-07 extension permissions are **per-origin**: readers must approve the
  signer on each `<subdomain>.<base-domain>` separately. Expected; document
  in the UI ("approve your signer for this site").
- DN session state must be valid on the subdomain. If session cookies are
  currently scoped to the main domain only, widen to the base domain or
  implement a lightweight subdomain login — decision needed at
  implementation time (open question Q7 in the master plan).
- NIP-46 remote signing works cross-origin and is the smoother path for
  returning readers; reuse the existing signer modal flow.

## Caching

- Interaction reads must never bypass the site page cache into per-request
  relay fan-out: highlight/reaction data is loaded lazily (Turbo Frame or
  fetch) and cached per article coordinate with short TTLs.
- Cache invalidation on own-action: after a reader publishes, update the UI
  optimistically; do not purge shared caches per interaction.

## Tests

- Unit: gated-highlight scope-tag enforcement in the publishing chokepoint.
- Functional: interaction endpoints on an Unfold host resolve the correct
  site context; anonymous create attempts are rejected.
- Gherkin: highlight creation on gated vs public articles.
