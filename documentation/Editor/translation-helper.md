# Translation Helper

## Overview

The Translation Helper is a hidden page that allows any logged-in user to translate a Nostr long-form article (kind 30023) into another language. It imports an existing article by `naddr` or coordinate, displays the original markdown content in a read-only pane alongside an editable pane for the translation, and publishes the result as a new kind 30023 event linked to the original.

## Route

```
GET /translation-helper
```

- **Access**: Requires `ROLE_USER` (any logged-in npub).
- **Navigation**: This route is intentionally not listed in any menu or sidebar. Share the URL directly with translators.

## Usage

1. **Import**: Paste an `naddr1…` identifier or a raw coordinate (`30023:<pubkey>:<slug>`) into the import field and click **Import**.
2. **Edit metadata**: The title, summary, slug (d-tag), and language fields are pre-filled. Adjust them as needed.
3. **Translate**: The original markdown appears in the left pane (read-only). Write the translation in the right pane.
4. **Publish**: Click **Sign & Publish Translation**. The browser's Nostr signer (NIP-07 extension or NIP-46 bunker) signs the event, and it is published to the user's relay list.

## Event Structure

The published translation event follows the standard NIP-23 kind 30023 format with additional tags:

### Tags added by the translation helper

| Tag | Example | Purpose |
|-----|---------|---------|
| `a` | `["a", "30023:<original-pubkey>:<original-slug>"]` | Points back to the original article (NIP-23 reference) |
| `zap` | `["zap", "<original-pubkey>", "", "1"]` | Credits the original author for zaps. If the original event already has `zap` tags, those are preserved as-is instead. |
| `L` | `["L", "ISO-639-1"]` | NIP-32 label namespace declaration |
| `l` | `["l", "de", "ISO-639-1"]` | NIP-32 language label (ISO-639-1 code of the translation) |
| `published_at` | `["published_at", "1710500000"]` | Timestamp of translation publication |
| `client` | `["client", "Decent Newsroom"]` | Client identifier |

### Tags carried over from the original

- `image` — cover image
- `t` — topic/hashtag tags

### Tags replaced

- `d` — set to a new slug (default: `<original-slug>-<lang>`)
- `title` — translation title
- `summary` — translation summary

## API Endpoint

```
POST /api/translation/fetch-article
```

**Request body:**
```json
{ "input": "naddr1..." }
```
or
```json
{ "input": "30023:<hex-pubkey>:<slug>" }
```

**Response:**
```json
{
  "success": true,
  "source": "database",
  "coordinate": "30023:abc123...:my-article",
  "event": { ... },
  "author": "Author Name"
}
```

The endpoint first checks the local database, then falls back to fetching from Nostr relays.

## Files

| File | Description |
|------|-------------|
| `src/Controller/Editor/TranslationHelperController.php` | Page route + article fetch API |
| `templates/editor/translation_helper.html.twig` | Twig template with side-by-side layout |
| `assets/controllers/editor/translation_helper_controller.js` | Stimulus controller handling import, event construction, signing, publishing |
| `assets/styles/04-pages/translation-helper.css` | Page-specific CSS |

