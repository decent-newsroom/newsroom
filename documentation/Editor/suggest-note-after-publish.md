# Post-Publish Note Suggestion

## Overview

After successfully publishing an article (kind 30023), the user is redirected to the article page where a banner suggests they announce the article with a short kind 1 note. This helps authors promote their articles across the Nostr network.

## Flow

1. **Article publish API** (`POST /api/article/publish`) sets a Symfony flash message (`article_published`) containing the article's title, slug, and pubkey as JSON.
2. On the **article page**, the flash message is consumed and rendered as a banner with a CTA button: "Announce it with a note".
3. Clicking the CTA opens a **modal dialog** with a pre-filled textarea containing the article title and a `nostr:naddr1…` link.
4. The user can edit the text, then click **"Sign & Publish Note"**.
5. The Stimulus controller (`nostr--nostr-suggest-note`) builds a kind 1 event with:
   - The user's composed content
   - An `a` tag referencing the article coordinate (`30023:{pubkey}:{slug}`)
6. The event is signed via NIP-07 extension or remote signer (bunker).
7. The signed event is sent to `POST /api/note/publish`, which publishes it to the user's write relays.

## Components

| Component | Path |
|-----------|------|
| Stimulus controller | `assets/controllers/nostr/nostr_suggest_note_controller.js` |
| API endpoint | `src/Controller/Api/NotePublishController.php` |
| Flash message setup | `src/Controller/Editor/EditorController.php` (in `publishNostrEvent`) |
| Template (banner + modal) | `templates/pages/article.html.twig` |
| Styles | `assets/styles/03-components/suggest-note.css` |

## Dismissal

The banner can be dismissed without publishing a note. The flash message is consumed on first load, so it won't appear again on subsequent visits.

