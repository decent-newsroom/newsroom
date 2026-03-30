# Follow Pack Setup

## Overview

The Follow Pack Setup feature allows logged-in users to create and manage a **follow pack** (Nostr kind 39089 event) from a dedicated setup page (`/settings/follow-pack`). A follow pack is a curated list of recommended writers that the user publishes to Nostr relays and sets as their personal recommendation list.

The follow pack info is displayed in the **layout aside** (right sidebar) on the profile page, showing a compact list of recommended writers for both the owner and visitors.

## User Flow

### Dedicated Setup Page (`/settings/follow-pack`)

1. The page shows:
   - A **title field** (defaults to "My Recommended Writers")
   - A **selected writers** display showing chosen users as chips
   - A **search field** for finding users (reuses `/api/users/search`)
   - **Follow suggestions** from the user's kind 3 follow list (prioritized)
   - An **existing packs sidebar** for selecting/switching between packs
2. Clicking **"Publish Follow Pack"**:
   - Builds a kind 39089 event skeleton with `d`, `title`, `alt`, and `p` tags
   - Signs the event via the user's Nostr signer (NIP-07/NIP-46)
   - Publishes to relays via `POST /api/settings/event/publish`
   - Stores the coordinate (`39089:pubkey:d-tag`) on the User entity via `POST /api/settings/follow-pack/set`

### Profile Page — Aside Sidebar

- **Owner with follow pack**: Shows "Recommended Writers" heading, compact member avatar+name list (up to 10), and an "Edit Follow Pack" link to the setup page.
- **Owner without follow pack**: Shows an info bubble ("Set up a list of writers you recommend.") with a "Set Up Follow Pack" link to the setup page.
- **Visitor**: If the viewed profile has a follow pack coordinate set, shows "Recommended Writers" heading with a compact member list.

### Profile Page — Author Section

- **Owner**: Shows a compact info bubble with a link to the setup page.
- **Visitor**: Shows "Recommended Writers" label if the profile has a follow pack.

### Settings Page (Events Tab)

- The Kind 39089 event card shows:
  - Count of follow packs
  - Whether a recommendation list is selected (★ badge)
  - List of packs with title, member count, and selection state
  - **Manage / Set Up** button linking to `/settings/follow-pack`

## Technical Details

### Database Changes

**Migration:** `Version20260330120000`

- Adds `follow_pack_coordinate VARCHAR(500) DEFAULT NULL` column to `app_user` table
- Stores the Nostr event coordinate (`39089:pubkey:d-tag`) of the user's selected follow pack

### Kind Bundle Update

- `KindsEnum::FOLLOW_PACK` (39089) added to `KindBundles::USER_CONTEXT` array
- Allows the generic `api_settings_event_publish` endpoint to accept kind 39089 events

### Routes

| Method | Path | Name | Description |
|--------|------|------|-------------|
| `GET` | `/settings/follow-pack` | `follow_pack_setup` | Dedicated setup page |
| `POST` | `/api/settings/follow-pack/set` | `api_settings_follow_pack_set` | Store coordinate |
| `GET` | `/api/settings/follow-pack` | `api_settings_follow_pack_get` | Get follow pack data |

### Templates

| Template | Purpose |
|----------|---------|
| `settings/follow-pack.html.twig` | Dedicated setup page |
| `profile/author-tabs.html.twig` `{% block aside %}` | Follow pack sidebar in layout right column |
| `partial/_author-section.html.twig` | Compact info bubble + link |
| `settings/tabs/_events.html.twig` | Events tab card with link to setup page |

### Stimulus Controller

**File:** `assets/controllers/nostr/nostr_follow_pack_controller.js`

**Controller name:** `nostr--nostr-follow-pack`

On the dedicated page (no `infoBubble` target), the form is auto-expanded.

**Values:**
- `publishUrl` — Backend publish endpoint URL
- `setCoordinateUrl` — Coordinate storage endpoint URL
- `searchUrl` — User search API URL (default: `/api/users/search`)
- `followsPubkeys` — Array of hex pubkeys from user's kind 3 follows
- `followsProfiles` — Resolved profile objects for follow suggestions
- `existingMembers` — Current pack members (for editing)
- `existingDtag` — d-tag of existing pack (for updates)
- `selectedCoordinate` — Currently selected follow pack coordinate

### CSS

**File:** `assets/styles/03-components/follow-pack.css`

Key sections:
- `fp-` prefix for setup form elements
- `fp-page-` prefix for dedicated page layout (responsive grid)
- `fp-aside__` prefix for layout aside sidebar elements

### Translation Keys

- `follow_pack.page_title` / `follow_pack.page_tagline` — Dedicated page heading
- `follow_pack.info_bubble` — Info bubble text
- `follow_pack.setup_button` / `follow_pack.edit_button` — Action links
- `follow_pack.title_label` / `follow_pack.title_placeholder` — Title field
- `follow_pack.selected_writers` / `follow_pack.search_label` / `follow_pack.search_placeholder` — Form fields
- `follow_pack.publish_button` — Publish action
- `follow_pack.existing_packs` / `follow_pack.members` / `follow_pack.select_button` / `follow_pack.selected` — Existing packs UI
- `follow_pack.recommended_writers` / `follow_pack.more_writers` — Sidebar display
- `settings.events.followPack*` — Settings events tab labels

## Nostr Event Structure (Kind 39089)

```json
{
  "kind": 39089,
  "tags": [
    ["d", "follow-pack-1711800000000"],
    ["title", "My Recommended Writers"],
    ["alt", "Follow pack: My Recommended Writers"],
    ["p", "<hex-pubkey-1>"],
    ["p", "<hex-pubkey-2>"]
  ],
  "content": "",
  "created_at": 1711800000
}
```

The event coordinate format is: `39089:<author-pubkey>:<d-tag>`
