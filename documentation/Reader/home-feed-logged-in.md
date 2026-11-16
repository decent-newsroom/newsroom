# Unified discovery and author streams

The home page at `/` is the same reading destination for visitors and signed-in
readers. `/discover` redirects there. The former marketing-only and authenticated
home variants no longer compete with a separate tabbed Discover page.
This implements the editorial direction in the
[graph product design proposal](graph-product-design-plan.md), with the requested
single-stream behavior taking precedence over its older profile-tab suggestion.

## Content selection

| Surface | Retained sources |
|---|---|
| Public home | Recent articles, featured writers' articles, public highlights |
| Signed-in home | Public discovery plus discussed/followed/interest articles, the selected follow pack's articles, followed authors' highlights and long-form comments, local subscription updates |
| Author profile | Public articles, existing media kinds, highlights, authored/featured publications, reading lists and follow packs |
| Own profile | The public profile sources plus owner-only drafts |

No new event kinds, entitlements, payment terms, or relay discovery mechanisms
are introduced. Newsstand, Bookshelf, topics, search, collection management and
focused reading/editing routes remain available. Subscription notifications are
labeled as local updates; visiting home never marks them seen or read.
Recent public articles retain the persisted kind-0 author eligibility check;
the recent source does not queue missing profile metadata to fill its list.

`ContentStreamBuilder` merges the selected records by descending source date.
Coordinate-backed article/collection revisions are deduplicated without folding
case-sensitive identifiers or merging drafts with published work. Highlights
shared by public discovery and followed-author activity appear once. Source
labels survive deduplication. Equal-date rows use an identity tie-breaker.
Legacy collection snapshots without dates sort last instead of appearing new.
Local update records remain distinct notification rows.

The home stream also inserts featured-writer profile carousels as feed breaks.
They use the locally stored follow pack configured for the `ESSAYIST_WRITERS`
purpose (the same featured pack as Essayist), for visitors and signed-in
readers alike. If no site pack is configured, signed-in readers see members
of their own selected pack instead, labeled accordingly; that pack continues
to supply articles separately. Cards link to profiles and the originating pack, and do not follow
anyone or alter a kind-3 list. Missing or empty packs produce no break.
Suppressed, muted, excluded and current-reader profiles are omitted. Pack order
is preserved, up to 24 profiles are displayed per page, and larger packs rotate
their starting point on subsequent pages. Metadata is resolved in a bounded
batch through the existing cache with asynchronous refresh on misses.
The source is the `follow_pack_source` row with purpose `essayist_writers`;
its `39089:<author hex>:<d-tag>` coordinate must refer to a follow-pack event
already stored locally. There is currently no source-configuration UI. Without
that row, visitors see no suggestions; signed-in readers need a locally stored
selected pack. A configured site pack without a local event produces no break.
An operator can configure the existing site source with a database upsert,
substituting the coordinate of the intended locally stored public pack:

```sql
INSERT INTO follow_pack_source (purpose, coordinate, created_at, updated_at)
VALUES ('essayist_writers', '39089:<author hex>:<d-tag>', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (purpose) DO UPDATE
SET coordinate = EXCLUDED.coordinate, updated_at = EXCLUDED.updated_at;
```

This also changes the featured pack on the Essayist home page. Readers can
choose their own fallback pack at `/settings/follow-pack`.

Recommendations are inserted after five and, when there are enough entries and
profiles, fifteen content rows; short or empty streams still show one carousel.
They do not consume pagination slots or affect content ordering. Carousel
navigation supports touch, keyboard and buttons; if the carousel controller is
unavailable, its profile links remain horizontally scrollable. The home feed
itself is loaded via Turbo.

Both streams paginate the merged results at 20 rows per page, not one page per
content type. Existing source bounds still apply: for example personalized
articles 60, selected-pack articles 50, activity 60, updates 50, author articles
500, highlights 50, and the media service's existing 200-record local window.
All 200 media records can now participate in profile pagination, rather than
only the former first 24. These are bounded local selections, not complete
Nostr-network inventories.

## Rendering and navigation

- `DefaultController::index()` always renders `home.html.twig`. Its frame loads
  `/home/stream`; its explicit route priority avoids the vanity-profile pattern.
- Old `/home/tab/{tab}` links lead to the unified home.
- Author npub/vanity entry points and ordinary old tab URLs lead to the profile
  `stream` route. Legacy frame endpoints remain compatible for existing callers.
- `HomeFeedController` reuses the existing source-selection functions.
  `FeaturedWritersFeed` is shared with the standalone featured-writers page.
- `AuthorController` reads public articles directly from the repository and
  media/highlights from their existing local adapters. Draft lookup and inclusion
  happen only after the authenticated identity matches the profile owner.
- Editorial profile relationships remain worker-built. A cold overview cache
  does not synchronously scan publications and does not prevent other work from
  appearing. A visible pending message distinguishes that state from absence.
- `content--stream` uses one multi-topic Mercure connection on a profile.
  New content reveals a notice; it does not replace the reader's position
  automatically. Refresh is explicit, and disconnect closes the connection.
- Personal stream responses are private/no-store. Home/profile pages opt out of
  Turbo snapshot caching; no event bodies are stored in browser feed caches.
- Pagination URLs survive reload and browser navigation. Home pages carry their
  page into the initial frame URL; profile pagination stays in its own frame.

The shared navigation keeps Discover, Reading Nook, and Newsroom visible with
workspace-local links underneath. Existing paper/ink/green tokens, serif headings,
square edges and no elevation carry through stream rows and shared previews.
Preview identity fields live in a native disclosure; warning gating and ordered
references are unchanged. Reading Nook has one section navigator rather than a
duplicate navigator/count dashboard in the context rail. Action menus use
ellipsis rather than the expansion/addition plus sign.

## Boundaries

This is a presentation and feed convergence, not every future phase of the design
proposal. It does not introduce a new split-pane selection/history model, broader
graph resolution, viewer switches, low-data mode, commercial offer reconciliation,
or a new payment bundle. Existing lazy workspace disclosures and authorization
remain authoritative.

The old tab controllers still serve unrelated consumers such as the Essayist
surface; they are not removed globally. No database rows, roles, collections,
subscriptions, or historical migrations are deleted. The reason for retiring
the two home variants and profile tab navigation is the requested coherent feed
and reduction of apparently empty profiles, not a measured production-usage claim.

## Related documentation

- [Reading Nook](reading-nook.md)
- [Event workspace contract and delivered capabilities](event-workspace-iteration-plan.md)
- [Follow-pack setup](../Newsroom/follow-pack-setup.md)
- [NIP-51 lists](../NIP/51.md)
