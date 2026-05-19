# Essayist Personalized Home Page

**Route:** `GET /essayist/home`  
**Access:** `ROLE_ESSAYIST_MEMBER` or `ROLE_ADMIN`

## Overview

The Essayist home page is a personalized, tabbed feed for relay members. It queries the `strfry-essayist` relay directly and filters results through the member's social graph (follows) and topic interests (kind:10015).

## Tabs

### For You (`/essayist/home/tab/foryou`)
Merges two sources from the Essayist relay, deduplicated by `pubkey:slug`:
1. Articles from pubkeys in the user's kind:3 follows list
2. Articles whose `t` tags overlap with the user's kind:10015 interest tags

Source labels (`follows` / `interests`) are passed to the card component for display.

### Follows (`/essayist/home/tab/follows`)
Articles published to the Essayist relay by pubkeys in the user's kind:3 follows list.  
Falls back to a relay backfill via `UserProfileService::getFollows()` if the kind:3 event is not yet in the local DB.

### Topics (`/essayist/home/tab/topics`)
Articles published to the Essayist relay that match any of the user's kind:10015 interest hashtags (matched via the relay's `#t` filter).

## Featured Writers Pack (Sidebar)

The aside block shows the configured `ESSAYIST_WRITERS` follow pack (managed via the admin follow-pack source UI). Up to 8 members are displayed with their avatar and display name. A link to the full follow-pack page is provided.

If no `ESSAYIST_WRITERS` source is configured, the aside shows only the navigation links.

## Architecture

### Controller
`App\Controller\EssayistController::home()` (`GET /essayist/home`)  
`App\Controller\EssayistController::homeFeedTab()` (`GET /essayist/home/tab/{tab}`)

Both methods perform manual role checking (same as the feed page). Tab methods are private helpers:
- `essayistFollowsTab()`
- `essayistTopicsTab()`
- `essayistForYouTab()`

### Service Extension
`EssayistFeedService` has been extended with:
- `fetchByPubkeys(array $pubkeys, int $limit): array` — issues a `REQ` filter with `authors` set
- `fetchByTopics(array $hashtags, int $limit): array` — issues a `REQ` filter with `#t` set
- `buildCard()` now also captures `t` tags and exposes them as `$card->topics`

The original `fetchLatest()` was refactored to delegate to a private `doFetch(Filter $filter)` method that all public methods share.

### Templates
```
templates/essayist/
  home.html.twig          — main page with tab nav + Turbo Frame
  tabs/
    _foryou.html.twig     — For You tab partial
    _follows.html.twig    — Follows tab partial
    _topics.html.twig     — Topics tab partial
```

### UI Pattern
Reuses the same `content--home-tabs` Stimulus controller and `turbo-frame#home-tab-content` pattern as the main authenticated home page.

## Navigation
- Landing page member CTAs (`essayist.landing.hero.ctaMember`, `essayist.landing.join.memberCta`) now link to `/essayist/home`.
- The aside nav on `/essayist/home` links back to `/essayist/feed` (all articles, unfiltered) and `/essayist` (landing page).

## Access Control
- Unauthenticated users are redirected to `/essayist?join_status=login_required`.
- Authenticated users without `ROLE_ESSAYIST_MEMBER` are redirected to `/essayist?join_status=access_denied`.
- `ROLE_ADMIN` bypasses the membership check (same as the feed page).

