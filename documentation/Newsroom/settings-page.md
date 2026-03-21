# User Settings Page

## Overview

The settings page (`/settings`) provides a centralized location for authenticated users to manage their Nostr profile, view their event data, and manage their subscriptions.

## Routes

| Route | Name | Method | Description |
|-------|------|--------|-------------|
| `/settings` | `settings` | GET | Main settings page (auth required) |
| `/api/settings/profile/publish` | `api_settings_profile_publish` | POST | Publish signed kind 0 metadata event |
| `/api/settings/event/publish` | `api_settings_event_publish` | POST | Publish any signed user-context event |

## Architecture

### Controller

`src/Controller/SettingsController.php` handles:
- Loading all user context events from the DB via `EventRepository::findLatestByPubkeyAndKind()`
- Parsing kind 0 profile metadata from both JSON content and tags
- Looking up subscriptions (vanity name, active indexing, publication subdomain)
- Two API endpoints for publishing signed events to relays

### Templates

- `templates/settings/index.html.twig` — main tabbed layout
- `templates/settings/tabs/_profile.html.twig` — profile editing form
- `templates/settings/tabs/_events.html.twig` — events dashboard
- `templates/settings/tabs/_subscriptions.html.twig` — subscriptions

### Stimulus Controllers

- `assets/controllers/ui/settings_tabs_controller.js` — tab switching with URL hash deep-linking
- `assets/controllers/nostr/nostr_settings_profile_controller.js` — kind 0 profile editing, signing, and publishing

### CSS

- `assets/styles/04-pages/settings.css` — settings page styles

## Tabs

### 1. Profile

Edits kind 0 metadata. The form collects:
- Display name, username, about, picture URL, banner URL
- NIP-05 identifier, Lightning address (lud16), website

On submit, the controller builds a kind 0 event with **both** tags (new format) and JSON content (legacy format) for maximum compatibility with other Nostr clients. The event is signed via the user's Nostr signer (NIP-07/NIP-46) and published to their declared relays.

### 2. Events

Read-only dashboard showing all Nostr event kinds that Decent Newsroom uses:

| Kind | Name | Notes |
|------|------|-------|
| 0 | Profile Metadata | Links to profile editor tab |
| 3 | Follows | Shows followed account count |
| 10002 | Relay List | Shows relay count and first 5 relays |
| 10003 | Bookmarks | Links to bookmarks page |
| 10015 | Interests | Links to interests editor; setup button if none |
| 10020 | Media Follows | Shows count; hint text if none |
| 10000 | Mute List | Shows muted account count |
| 10001 | Pin List | Shows pinned item count |
| 10063 | Blossom Servers | Shows server count |
| 30015 | Interest Sets | Shows last updated date |
| 39089 | Follow Packs | Shows pack count |

Each card shows whether the event exists (✓/✗), last updated date, and relevant counts.

### 3. Subscriptions

Shows the status of:
- **Vanity Name (NIP-05)** — links to `/subscription/vanity`
- **Active Indexing** — links to `/subscription/active-indexing`
- **Publication Subdomain** — links to `/subscription/publication-subdomain`


## Event Publishing Flow

1. User edits profile fields in the form
2. Stimulus controller collects values, builds kind 0 event skeleton
3. `getSigner()` obtains the Nostr signer (extension or remote bunker)
4. Event is signed via `signer.signEvent()`
5. Signed event POSTed to `/api/settings/profile/publish`
6. Backend validates fields, verifies signature, persists to DB, publishes to user's declared relays
7. Success/failure feedback shown in UI

## Navigation

Settings is accessible from:
- The logged-in user menu (sidebar) via the "Settings" link
- Direct URL: `/settings`

Tab deep-linking via URL hash: `/settings#events`, `/settings#subscriptions`

