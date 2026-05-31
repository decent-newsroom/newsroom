# Profile Editorial Tab: Featured Collections

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

