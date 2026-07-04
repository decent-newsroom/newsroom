# My Content: article delete action

## What was added

The `/my-content` page now includes a delete action for each published article row.

When clicked, the action:

1. Builds a NIP-09 delete request event (`kind: 5`) for that article.
2. Includes both:
   - `e` tag with the article event id
   - `a` tag with the article coordinate (`30023:<pubkey>:<slug>`)
3. Requests a client-side signature from the active Nostr signer.
4. Publishes the signed event through `POST /api/settings/event/publish`.

## Frontend wiring

- Template: `templates/my_content/index.html.twig`
  - Adds `del` action button on article rows.
  - Attaches Stimulus controller values:
    - event id
    - coordinate
    - publish URL
    - article title

- Stimulus controller: `assets/controllers/content/content_my_content_delete_controller.js`
  - Uses `getSigner()` from `signer_manager`.
  - Confirms with the user before signing.
  - Signs and publishes the kind `5` event.
  - Reports status via `window.showToast`.

## Notes

- This action sends a deletion request event; it does not hard-delete DB rows directly from the UI.
- Relay acceptance still depends on relay policy and author/event ownership semantics of NIP-09.

