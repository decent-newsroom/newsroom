# Featured Reading Lists in the Author Overview

## Overview

The author profile **Overview** tab (`templates/profile/tabs/_overview.html.twig`)
surfaces the publications an author *created* and is *featured in*. In addition to
magazines and follow packs, it now includes two reading-list sections:

- **Reading lists** — flat kind `30040` events with `type: reading-list` authored by
  the profile owner.
- **Featured in reading lists** — reading lists authored by *other* users that include
  at least one article written by the profile owner.

## The bug this fixes

Previously, reading lists never appeared on the Overview tab. Featured detection ran
through two paths, both of which excluded reading lists:

1. `MagazineRepository::findByContributor()` matches the `contributors` JSON column,
   which `MagazineProjector` only populates for **nested magazines** (it walks
   category → article coordinates). Flat reading lists are never projected into
   `Magazine` entities, so they have no `contributors` row.
2. The controller fallback scanned publication-index events but filtered
   `type == 'magazine'`, skipping `type: reading-list` events entirely.

## Detection model (coordinate-based)

Featured authorship is derived from the pubkey segment of each article `a` tag, not from
`p` tags. Article coordinates use the form `kind:pubkey:slug`; for every `a` tag whose
kind is `30023` (longform) or `30024` (draft), the middle `pubkey` segment is the featured
author. This is implemented once in
`AuthorController::extractFeaturedAuthorPubkeys()` and works **retroactively** for all
existing lists — no republish required.

### Query methods (`src/Controller/AuthorController.php`)

- `getAuthorReadingLists(string $pubkey)` — kind `30040` events by `$pubkey` with
  `type == reading-list` and no nested `30040:` references, deduplicated by slug
  (newest revision wins).
- `getFeaturedReadingLists(string $pubkey)` — reading-list events authored by others
  whose `a` tags reference a `30023`/`30024` coordinate with `$pubkey`. Uses a targeted
  JSONB containment query (`jsonb_array_elements` + `LIKE '30023:<pubkey>:%'`),
  deduplicated by `pubkey:slug`, capped at 50, ordered newest first.

Both return plain arrays (with `createdAt` as a Unix timestamp integer) so they survive
the JSON-encoded profile-tab cache. The same logic is mirrored in the async cache builder
`RevalidateProfileCacheHandler::buildOverviewData()`.

Standalone reading lists are also excluded from the "magazines" sections
(`getAuthorMagazines` skips `type == reading-list` / `type == category`) to avoid
double-listing.

## `p` tags at creation (interoperability)

Detection does not depend on `p` tags, but they are now emitted so other Nostr clients
(and notifications) can attribute featured authors:

- **Reading lists** — `ReadingListController::buildReadingListEvent()` appends one
  `['p', <pubkey>]` per unique article author. The workspace Stimulus controller
  (`publishing_reading_list_workspace_controller.js`) re-derives the `p` tags client-side
  whenever article inputs change, keeping the signed event and preview in sync.
- **Magazines** — `MagazineWizardController` appends `p` tags to each category event
  (from that category's articles) and aggregates them across all categories onto the
  top-level magazine index event.

Author pubkeys are extracted from the article coordinates themselves, so no extra
database lookup is needed. All `p` tag lists are de-duplicated.

## Notes

- `MagazineProjector` is deprecated (superseded by the graph layer); new logic lives in
  the controller/query and creation paths, not in the projector.
- Overview data is cached (24h TTL, stale-while-revalidate). New fields are part of the
  cached payload and the empty-payload guard, so a profile with only reading lists is no
  longer treated as an empty (poisoned) cache entry.
