# Newsroom Navigation Refactor Plan

This document captures the next iteration of the application navigation so it can be refined before implementation. The current left sidebar in `templates/layout.html.twig` mixes public discovery, private reading tools, authored content management, admin utilities, and creation actions in one long list. The result is a navigation that is visually long, cognitively noisy, and unclear about which destinations are core product areas versus secondary utilities.

## Overview

The refactor should make the global navigation answer **"what part of the product am I in?"** instead of listing every possible destination. Page-local navigation inside feature hubs should answer **"what can I do here?"**.

The intended split is:

- **Reading Nook** = private reading/library space
- **Newsroom** = authored content and publishing workspace
- **Global sidebar** = a small set of top-level product areas
- **User/Admin menus** = utilities, account actions, and privileged tools

This keeps the brand front and centre by using **Newsroom** as the name of the publishing workspace instead of a generic label like “Studio”.

## Current problems

### 1. The left sidebar mixes too many mental models

The current sidebar includes:

- Public discovery (`discover`, `forum`, `highlights`, `relay_feed_index`)
- Publication/discovery surfaces (`newsstand`, `lists`, `follow_packs`)
- User-private reading destinations (`my_bookmarks`, `my_interests`, `reading_list_index`)
- User-authored destinations (`my_content`, `my_magazines`, `media_manager`)
- Creation actions (`mag_wizard_new`, `reading_list_index`, `editor-create`)
- Admin-only destinations (`expression_list`, `spell_list`)
- Secondary utilities (`updates_index`, feedback)

These have different audiences, frequencies, and urgency, but are presented as peers.

### 2. `ReadingNookController` is already a hub, but it currently spans two domains

`src/Controller/Reader/ReadingNookController.php` already aggregates:

- bookmarks
- interests
- reading lists
- follow packs
- authored content
- magazines

That aggregation proved the value of a hub page, but it also revealed that **private reading/collecting** and **authored publishing** are separate workflows and should no longer share the same navigation bucket.

### 3. Secondary and advanced items compete with primary navigation

Items like `relay_feed_index`, `media_manager`, `updates_index`, `expression_list`, and `spell_list` add length and noise to the main list even though they are not first-order destinations for most users.

## Target information architecture

## Global navigation

The global left sidebar should be reduced to top-level product areas.

### For anonymous users

- **Articles**
- **Topics**
- **Highlights**
- **Newsstand**
- **Collections** (optional; keep only if it is a major browse surface)

### For authenticated users

- **Articles**
- **Topics**
- **Highlights**
- **Newsstand**
- **Reading Nook**
- **Newsroom**
- **Create**

### Principles

- Keep the number of first-level choices low.
- Prefer a single top-level entry for each product area.
- Do not list every "my ..." page in the global nav.
- Treat the global nav as **mode switching**.

## Reading Nook

`Reading Nook` becomes the private library / reading management area.

### It should contain

- **Overview**
- **Bookmarks**
- **Interests**
- **Reading Lists**
- **Follow Packs**
- optional future: **Saved Magazines** or **Following** if there is a distinct read-side concept later

### It should not contain

- Drafts
- Published authored articles
- Media manager
- Magazine publishing/ownership management

### Reading Nook local navigation

A page-local menu inside the Reading Nook can expose:

- Overview
- Saved
  - Bookmarks
  - Interests
- Collections
  - Reading Lists
  - Follow Packs
- Back to Newsroom

### Controller direction

Over time, `ReadingNookController` should stop surfacing authored content sections such as:

- `SECTION_MY_CONTENT`
- `SECTION_MAGAZINES`

This can happen after the Newsroom hub exists, so the transition does not remove access before the replacement is ready.

## Newsroom

`Newsroom` becomes the authenticated publishing workspace and the branded home for authored content.

### It should contain

- **Overview**
- **My Content**
  - Drafts
  - Published articles
- **My Magazines**
- **Media Manager**
- authored **Reading Lists / Curations** if these are treated primarily as publishing tools

### Newsroom local navigation

Suggested local menu:

- Overview
- Articles
  - Drafts
  - Published
- Publications
  - Magazines
  - Reading Lists / Curations
- Media
- Create New
- Back to Discover

### Role in the product

The Newsroom area should feel like the user’s operational base for producing and organising their own output, distinct from Reading Nook’s read-side organisation.

## What stays, what moves

| Current item | Keep in global sidebar? | Target home |
|---|---:|---|
| `discover` | Yes | Global / Discover |
| `forum` | Yes | Global / Discover |
| `highlights` | Yes | Global / Discover |
| `newsstand` | Yes | Global |
| `lists` | Maybe | Global only if it is a major public browse area |
| `reading_nook` | Yes | Global (promoted, not hidden) |
| `my_bookmarks` | No | Reading Nook |
| `my_interests` | No | Reading Nook |
| `reading_list_index` | No as a “my …” destination | Reading Nook and/or Newsroom depending on page purpose |
| `follow_packs` | Usually no | Reading Nook or a dedicated collections browse page |
| `my_content` | No | Newsroom |
| `my_magazines` | No | Newsroom |
| `media_manager` | No | Newsroom |
| `updates_index` | No | User menu |
| `relay_feed_index` | No | Discover secondary page / “More” area |
| `expression_list` | No | Admin area |
| `spell_list` | No | Admin area |

## Navigation layers

The refactor should clearly separate four layers.

### 1. Global navigation

Small set of mode-switching destinations.

### 2. Feature-local navigation

Reading Nook and Newsroom each get their own local menu in the page shell or aside.

### 3. User menu

Account and personal utilities:

- updates
- stats
- logout
- essayist shortcut when relevant

### 4. Admin menu

Privileged operational tools:

- dashboard
- magazine admin
- analytics
- expressions
- spells

## Phased implementation plan

## Phase 0 — Audit and alignment

- Confirm which routes are public browse surfaces versus user-private tools.
- Decide whether `lists` is a public collections directory or mainly a user workspace.
- Decide whether authored reading lists belong in Newsroom while followed/saved lists belong in Reading Nook.
- Confirm whether `follow_packs` is primarily read-side or discovery-side.

### Output

- Final keep/hide/move matrix approved.
- Final names for top-level nav items approved.

## Phase 1 — Simplify the global left sidebar

Update `templates/layout.html.twig` so the main sidebar exposes only:

- Discover destinations
- Newsstand
- Reading Nook
- Newsroom
- Create

### Changes

- Unhide and promote `reading_nook`.
- Add a dedicated `Newsroom` entry.
- Remove individual personal links from the left sidebar:
  - `my_content`
  - `my_bookmarks`
  - `my_magazines`
  - `reading_list_index`
  - `my_interests`
  - `media_manager`
  - `updates_index`
- Move admin-only destinations out of the main content list.
- Keep create actions together, ideally as a compact subsection or one expandable area.

### Goal

Make the first interaction with navigation shorter and clearer without changing underlying routes yet.

## Phase 2 — Strengthen Reading Nook as a read-side hub

Refine `templates/reader/reading_nook/index.html.twig` and `ReadingNookController` so Reading Nook has its own stable sub-navigation and a tighter scope.

### Changes

- Add a local Reading Nook menu.
- Reframe the page as a library overview.
- Keep aggregation for saved/collected items.
- Mark authored-content sections as transitional or remove them once Newsroom is ready.

### Goal

Users should understand that Reading Nook is for organising what they read, save, follow, and collect.

## Phase 3 — Introduce Newsroom as the authored workspace

Build a new Newsroom landing page or reuse existing management pages under a shared shell.

### Candidate entry points

- Reuse `my_content` as the initial Newsroom overview, then expand it.
- Add a dedicated controller/template for a Newsroom hub page that links to authored subsections.

### Changes

- Gather authored routes under the Newsroom label.
- Add local navigation for drafts, published articles, magazines, curations, and media.
- Decide whether the Newsroom shell lives in its own layout variant or uses the existing right aside.

### Goal

Authored content becomes easy to find without overloading the global sidebar.

## Phase 4 — Cleanup and convergence

Once users can reliably navigate via Reading Nook and Newsroom:

- Remove duplicate legacy shortcuts.
- Narrow `ReadingNookController` to read-side content only.
- Reduce duplicate functionality between `my_content`, `reading_list_index`, and any new Newsroom hub.
- Review translations and wording so “Reading Nook” and “Newsroom” are consistently distinct.

## Implementation notes

### Keep route churn low at first

The first pass should change navigation structure before changing route structure. This lowers risk and makes UX feedback easier to gather.

### Prefer hub pages over deep sidebar trees

A short sidebar plus strong hub pages is preferable to a permanently expanded tree. If nested menus are added later, they should appear inside the feature area rather than in the global nav.

### Avoid duplicating read-side and write-side concepts

Where an object has both a consumption view and an authored-management view, decide which one is primary in each area:

- Reading Nook = saved / followed / curated for reading
- Newsroom = authored / managed / published

## Key files likely involved

| File | Role in the refactor |
|---|---|
| `templates/layout.html.twig` | Main left sidebar simplification |
| `templates/components/UserMenu.html.twig` | User/admin utility relocation |
| `src/Controller/Reader/ReadingNookController.php` | Remove authored content from Reading Nook over time |
| `templates/reader/reading_nook/index.html.twig` | Reading Nook local navigation and hub framing |
| `src/Controller/Newsroom/MyContentController.php` | Likely starting point for the Newsroom authored workspace |
| `src/Controller/Newsroom/ReadingListController.php` | Clarify authored lists/curations role |
| `translations/messages.*.yaml` | Distinct labels for Reading Nook and Newsroom |

## Open questions

- Is `lists` primarily a public browse surface or a private management surface?
- Are follow packs closer to discovery, collecting, or publishing for most users?
- Should authored reading lists live only in Newsroom while followed/saved lists live in Reading Nook?
- Does Newsroom need its own shell layout, or is a hub page enough for the first iteration?
- Should the Create area remain a static subsection or become a contextual action inside Newsroom?

## Proposed success criteria

The refactor is successful when:

- the main left sidebar is visibly shorter
- users can distinguish read-side and write-side spaces immediately
- Reading Nook is clearly for personal library management
- Newsroom is clearly for authored output and publishing
- admin and utility links no longer compete with primary navigation
- old routes remain reachable during transition, even if no longer top-level

