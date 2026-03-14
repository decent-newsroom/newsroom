# Interests Editor Feature

## Overview

The My Interests page (`/my-interests`) now includes an interactive editor that allows users to create or edit their Nostr interests list (NIP-51, kind 10015). Previously, users had to use an external Nostr client to publish an interests event. Now they can do it directly from the application.

## How It Works

### User Flow

1. User navigates to `/my-interests`
2. If the user has no interests yet, they see a "Create My Interests" button. If they already have interests, they see "Edit My Interests".
3. Clicking the button reveals the interests editor panel.
4. The editor shows:
   - **Selected Tags** — current tags displayed as removable pills
   - **Custom Tag Input** — text input to add arbitrary tags (comma or space separated)
   - **Popular Tags** — tags from ForumTopics, grouped by category (Lifestyle, Tech, Art & Culture, etc.), displayed as clickable chips
5. User selects/deselects tags by clicking chips or adding custom ones.
6. User clicks "Sign & Publish Interests" to sign the event with their Nostr signer and broadcast it to relays.
7. Page reloads to show the updated interests.

### Technical Architecture

#### Backend

- **`ForumController::myInterests()`** — passes `popularTags`, `groupedTags`, and `currentInterestTags` to the template
- **`ForumController::publishInterests()`** — new `POST /api/interests/publish` endpoint that:
  - Receives a signed kind 10015 event
  - Validates required fields and event kind
  - Verifies the event signature
  - Publishes to the user's relays via `NostrClient::publishEvent()`
  - Returns relay results as JSON
- **`ForumTopics::allUniqueTags()`** — new static helper returning all unique tags as a flat array
- **`ForumTopics::groupedTags()`** — new static helper returning tags grouped by category

#### Frontend

- **`nostr_interests_controller.js`** — Stimulus controller managing:
  - Tag selection state (`Set<string>`)
  - Chip toggle interactions
  - Custom tag input (Enter key or Add button)
  - Selected tag removal
  - Kind 10015 event skeleton construction
  - Signer integration via shared `getSigner()` from `signer_manager.js`
  - Backend publishing and page reload

#### Styles

- Added to `assets/styles/04-pages/forum.css`:
  - `.interests-editor` panel (collapsible)
  - `.interests-chip` tag chips with selected state
  - `.interests-selected-tag` removable pills
  - `.interests-custom__row` input layout
  - Responsive breakpoints for mobile

### NIP-51 Event Structure

The published event follows this structure:

```json
{
  "kind": 10015,
  "created_at": 1740700000,
  "tags": [
    ["t", "bitcoin"],
    ["t", "nostr"],
    ["t", "photography"],
    ["t", "cooking"]
  ],
  "content": "",
  "pubkey": "<user-pubkey>"
}
```

Kind 10015 is a replaceable event — publishing a new one replaces any previous interests list for that pubkey.

## Files Modified

- `src/Controller/Reader/ForumController.php` — added publish endpoint, passed editor data to template
- `src/Util/ForumTopics.php` — added `allUniqueTags()` and `groupedTags()` static methods
- `assets/controllers/nostr/nostr_interests_controller.js` — new Stimulus controller
- `assets/styles/04-pages/forum.css` — interests editor styles
- `templates/forum/my_interests.html.twig` — added editor UI
- `CHANGELOG.md` — feature entry

