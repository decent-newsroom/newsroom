# Missing Nostr Event Kinds — Suggestions

> **Date:** 2026-03-19  
> **Scope:** Kinds documented in `documentation/NIP/` that are not yet tracked in `src/Enum/KindsEnum.php` but would benefit the newsroom application.  
> **Excluded:** Kind 1 (per request).

---

## Currently Tracked Kinds (KindsEnum.php)

| Kind | Name | NIP |
|------|------|-----|
| 0 | Metadata | 01 |
| 3 | Follows | 02 |
| 5 | *(strfry only, no enum)* | 09 |
| 6 | Repost | 18 |
| 7 | Reaction | 25 |
| 16 | Generic Repost | 18 |
| 20 | Image | 68 |
| 21 | Video | 71 |
| 22 | Short Video | 71 |
| 1063 | File Metadata | 94 |
| 1111 | Comments | 22 |
| 1450 | Tabular Data | — |
| 9735 | Zap Receipt | 57 |
| 9802 | Highlights | 84 |
| 10002 | Relay List | 65 |
| 10003 | Bookmarks | 51 |
| 10015 | Interests | 51 |
| 10020 | Media Follows | 68 |
| 27235 | HTTP Auth | 98 |
| 30003 | Bookmark Sets | 51 |
| 30004 | Curation Sets | 51 |
| 30005 | Curation Videos | 51 |
| 30006 | Curation Pictures | 51 |
| 30023 | Longform | 23 |
| 30024 | Longform Draft | 23 |
| 30040 | Publication Index | NKBIP-01 |
| 30041 | Publication Content | NKBIP-01 |
| 30078 | App Data | 78 |
| 39089 | Follow Pack | 51 |

Also fetched but without an enum case: **30015** (interest sets, used in `SyncUserEventsHandler`).

---

## Recommended Additions

### Tier 1 — High Value (directly supports existing or planned features)

#### Kind 5 — Event Deletion Request (NIP-09)

**Why:** strfry already ingests kind 5 in the router config, but the app has no enum case and no code to honor deletion requests. When an author deletes an article or curation set, the app should respect it by hiding or removing the corresponding DB entity. Without this, deleted articles remain visible indefinitely.

**Fetch bundle:** User Context (when the logged-in user deletes their own content) and strfry ingest (already there).

**Effort:** Add enum case, add a deletion handler that processes kind 5 events and marks affected articles/events as deleted.

---

#### Kind 10000 — Mute List (NIP-51)

**Why:** The app already has a server-side `MutedPubkeysService` for admin-managed mutes. Kind 10000 is the *user's own* mute list — pubkeys, hashtags, words, and threads the user doesn't want to see. Fetching this on login would let the app respect the user's mute preferences across clients (not just the admin mute list). Directly relevant for feed filtering.

**Fetch bundle:** User Context (add to `USER_CONTEXT` bundle). It's a replaceable event, same pattern as follows/interests.

---

#### Kind 10001 — Pin List (NIP-51)

**Why:** Explicitly mentioned in the backlog as "Pinned notes — implement pinned notes as part of the user's profile." This is the NIP-51 kind for it. Contains `e` tags pointing to events the user wants to showcase on their profile.

**Fetch bundle:** User Context. Replaceable, latest wins.

---

#### Kind 30015 — Interest Sets (NIP-51)

**Why:** Already fetched in `SyncUserEventsHandler` (hardcoded as `30015`) but has no `KindsEnum` case. The backlog "Upgrade topics" feature explicitly requires interest sets — the plan is to convert hardcoded topic lists into kind 30015 events manageable via an admin page.

**Fetch bundle:** User Context + Author Content. Addressable event (multiple per user, keyed by `d` tag).

---

#### Kind 9734 — Zap Request (NIP-57)

**Why:** The app already tracks kind 9735 (zap receipts) but not kind 9734 (zap requests). The backlog notes "Review fetching, saving and display of zaps — apparently no zaps are ever found on relays." Zap requests contain the sender's intent and message; without them, the app can only show that *a* zap happened but not display the sender's optional message or verify the zap amount matches the request. Pairing 9734 with 9735 gives full zap context.

**Fetch bundle:** Article Social (add to `ARTICLE_SOCIAL` bundle alongside 9735).

---

#### Kind 34139 — Playlist (Nostria)

**Why:** Explicitly in the backlog as "Implement playlists" with a full example event. References kind 36787 tracks. Needs an enum case to start processing.

**Fetch bundle:** Author Content (new content type for profile pages). Would need a new `AuthorContentType::PLAYLISTS` case.

---

#### Kind 10063 — Blossom Server List (NIP-B7)

**Why:** The app already uses Blossom for media uploads (`src/Service/Media/`). Kind 10063 is the user's list of preferred Blossom servers. Fetching this on login would allow the app to upload media to the user's own servers instead of a hardcoded default, and to resolve broken media URLs by checking the user's server list.

**Fetch bundle:** User Context. Replaceable event.

---

#### Kind 1984 — Report (NIP-56)

**Why:** Enables user-initiated content reporting (spam, nudity, illegal content). For a newsroom that ingests articles from the open network, having a reporting mechanism is essential for community moderation. Reports could feed into the admin dashboard.

**Fetch bundle:** Not fetched in bundles — this is an *outbound* event the user publishes. But the app could *query* for reports targeting displayed content to show moderation signals.

---

#### Kind 1985 — Label (NIP-32)

**Why:** Labels enable distributed content classification and moderation. Articles could be labeled with quality ratings, topic categories, or content warnings by trusted reviewers. The admin could maintain label-based filter policies. Also relevant for the "sensitive content" (NIP-36) use case — articles with `content-warning` labels could be hidden behind a click-through.

**Fetch bundle:** Article Social (query by `#a` tag to find labels for displayed articles). Or a new "Article Moderation" bundle.

---

### Tier 2 — Medium Value (nice-to-have, supports future features)

#### Kind 39092 — Media Starter Packs (NIP-51)

**Why:** The app already supports kind 39089 (Follow Pack / Starter Packs). Kind 39092 is the media-specific equivalent. Since the app has a separate media discovery section and media follows (kind 10020), media starter packs would be a natural addition for the media discovery page.

**Fetch bundle:** Same pattern as Follow Pack.

---

#### Kind 30000 — Follow Sets (NIP-51)

**Why:** Categorized groups of users. Could power "lists" features — e.g., a user creates a "Journalists" or "Tech Writers" follow set. The app's follow pack feature (39089) is similar but serves a different purpose (shared packs). Follow sets are personal categorization.

**Fetch bundle:** User Context or Author Content.

---

#### Kind 17 — External Content Reaction (NIP-25)

**Why:** The app already handles kind 7 reactions to nostr events. Kind 17 is reactions to external content (websites, podcasts). Since the app has RSS/podcast integration (the Podcasts tab, follow packs), being able to show reactions to podcast episodes would enrich that feature.

**Fetch bundle:** Article Social (if querying by external content ID).

---

#### Kind 1068 — Poll + Kind 1018 — Poll Response (NIP-88)

**Why:** Polls could be embedded in articles or used standalone for reader engagement. A newsroom that covers topics could use polls for audience interaction.

**Fetch bundle:** New content type. Not critical, but would differentiate the app.

---

### Tier 3 — Low Priority (track for awareness)

| Kind | Name | NIP | Why Not Now |
|------|------|-----|-------------|
| 30818 | Wiki Article | 54 | Different content model (AsciiDoc, collaborative). Consider if the publication content (30041) covers this. |
| 30311 | Live Event | 53 | Live streaming is not in the backlog. Track if live spaces become relevant. |
| 30315 | User Status | 38 | Nice profile decoration but not essential for a newsroom. |
| 30009 / 8 / 30008 | Badge Definition / Award / Profile Badges | 58 | Gamification. Low priority but could reward active contributors. |
| 9041 | Zap Goal | 75 | Fundraising. Could be relevant for independent journalists. |
| 31989 / 31990 | App Handlers | 89 | Cross-client discoverability. Only needed when the app wants to advertise itself as a handler for specific kinds. |

---

## Summary: Recommended KindsEnum Additions

```php
// Tier 1 — add now or next sprint
case DELETION_REQUEST = 5;        // NIP-09
case MUTE_LIST = 10000;           // NIP-51
case PIN_LIST = 10001;            // NIP-51 (backlog: pinned notes)
case INTEREST_SETS = 30015;       // NIP-51 (backlog: upgrade topics)
case ZAP_REQUEST = 9734;          // NIP-57 (backlog: review zaps)
case PLAYLIST = 34139;            // backlog: implement playlists
case BLOSSOM_SERVER_LIST = 10063; // NIP-B7 (already uses Blossom)
case REPORT = 1984;               // NIP-56
case LABEL = 1985;                // NIP-32

// Tier 2 — add when feature is planned
case MEDIA_STARTER_PACK = 39092;  // NIP-51
case FOLLOW_SETS = 30000;         // NIP-51
case EXTERNAL_REACTION = 17;      // NIP-25
case POLL = 1068;                 // NIP-88
case POLL_RESPONSE = 1018;        // NIP-88
```

## Impact on Fetch Bundles

The fetch bundles from the optimization plan have been updated to include these kinds:

| Bundle | Kinds |
|--------|-------|
| USER_CONTEXT | 0, 3, **5**, **10000**, **10001**, 10002, 10003, 10015, 10020, **10063**, **30015** |
| ARTICLE_SOCIAL | **7**, 1111, **1985**, **9734**, 9735, 9802 |
| AUTHOR_CONTENT | 20–22, **1111**, 9802, 10003, 10015, 30003–30006, **30015**, 30023, 30024, **34139** |


