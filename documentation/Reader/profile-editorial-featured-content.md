# Profile Editorial Tab: Featured Collections

**Presentation update:** these authored/featured sources now participate in the
[unified profile stream](home-feed-logged-in.md), alongside articles, media and
highlights. The editorial worker/cache contract described below is retained;
there is no separate Editorial tab in ordinary profile navigation.

The profile stream is centered and capped at `52rem` (832px at the default root
font size), while remaining fluid on smaller screens. This profile-only limit
does not change the home stream, author header or right-hand aside.

The profile's right-hand aside also has a compact **Featured in** section for
magazines, reading lists and follow packs. It is visible to owners and visitors,
independently of the current stream page, above the existing recommended-writers
sidebar. Featured entries remain in the stream, including on smaller screens
where the layout hides the aside.

`ProfileFeaturedContent` uses worker-built overview relationships only as candidate
identities. It batch-resolves current local events and applies suppression,
deletion, hidden-coordinate, reader-mute, scope and sensitive-content checks
before exposing signed titles. Missing local targets are omitted; rendering does
not fetch from relays or synchronously rebuild relationship inventories. Cold or
stale overview caches retain the stream's background revalidation behavior.
Stream refreshes and pagination update the aside through a Turbo Stream.

## Overview

The profile `Editorial` tab now shows two additional collection types where the profiled user is featured:

- Magazines where the user is included as a contributor.
- Follow packs where the user's pubkey appears in `p` tags.

This is shown in addition to existing authored collections (published magazines and authored follow packs).

## What Changed

### Backend

- `AuthorController::getOverviewTabData()` now returns:
  - `authorMagazines`
  - `existingFollowPacks`
  - `featuredMagazines`
  - `featuredInFollowPacks`
- Added helper methods:
  - `AuthorController::getFeaturedMagazines()`
  - `AuthorController::getFeaturedInFollowPacks()`
- Updated empty-payload guard in `AuthorController::isEmptyCachedTabPayload()` to treat overview as non-empty when any of the four collections is present.
- Updated `RevalidateProfileCacheHandler` overview payload to match the same editorial shape so async cache refresh does not overwrite the tab with old fields.
- Updated `RedisViewStore::isEmptyTabPayload()` overview logic to match the current editorial payload fields.

### Frontend

- `templates/profile/tabs/_overview.html.twig` now renders:
  - existing authored magazines section,
  - new "Featured in magazines" section,
  - existing authored follow packs section,
  - new "Featured in follow packs" section.
- Empty state now accounts for authored and featured collections.

## Notes

- Featured magazines use projected `Magazine` contributor data when available.
- Featured follow packs are discovered by scanning kind `39089` events for matching `p` tags.
- Results are deduplicated by stable identifiers (`slug` for magazines, `pubkey:dTag` for follow packs).
