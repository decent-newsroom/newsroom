# Nostr Address (naddr) Search Recognition

## Overview

When a user pastes a Nostr address (`naddr1…`, `nevent1…`, `note1…`, `nprofile1…`, or `npub1…`) into any search bar, the application recognizes the entity, decodes it client-side, validates it, and redirects to the appropriate page. For events not yet in the local database, a synchronous relay fetch is attempted first (the user is explicitly looking for this event, so a brief wait is acceptable). The async loading page is only shown as a last resort when all synchronous attempts fail.

## How It Works

### Client-Side Recognition (Stimulus Controller)

The `search--nostr-redirect` controller (`assets/controllers/search/nostr_redirect_controller.js`) intercepts form submissions on all search forms:

1. **Prefix detection** — checks if the input matches known NIP-19 prefixes (`npub1`, `naddr1`, `nevent1`, `note1`, `nprofile1`).
2. **Decoding & validation** — for `naddr` and `nevent`, the entity is decoded using `decodeNip19()` from `nostr-utils.ts`. For `naddr`, the required fields (pubkey, kind, identifier) are validated.
3. **Inline feedback** — before redirecting, an inline status message appears below the search input showing the relay count from the address (e.g., "Searching 3 relays from the address…").
4. **Redirect** — `npub` → `/p/{npub}`, all others → `/e/{identifier}`.

### Server-Side Event Lookup

The `EventController` (`src/Controller/EventController.php`) handles `/e/{naddr1…}`:

1. Decodes the NIP-19 entity using the PHP `nostriphant/NIP19` library.
2. Checks the local database first (fast path).
3. **Synchronous relay fetch** — always attempted before falling back to async:
   - **note:** queries local + content relays synchronously via `getEventById()`.
   - **nevent:** enriches relay list with author's NIP-65 relay list (when author pubkey is available), then queries all relays synchronously.
   - **naddr:** single sync call via `getEventByNaddr()` which prioritises hint relays, then author relays, then default relays as fallback.
4. If found, the event is persisted and the page renders immediately — no loading screen.
5. **Only if all sync attempts fail:** dispatches a `FetchEventFromRelaysMessage` via Symfony Messenger for a broader async relay search, and renders the loading page.

### Article URL Fallback

When a user visits an article URL (`/p/{npub}/d/{slug}`) and the Article entity isn't in the local database, `ArticleController` performs an inline synchronous relay fetch — the same `getEventByNaddr()` call — projects the article, and renders it directly. No redirects or intermediate pages.

### Loading Page (Async Fallback)

The loading page (`templates/event/loading.html.twig`) is only shown when all synchronous lookup attempts have failed. It uses the `content--event-fetch` Stimulus controller and shows contextual messages:

- **When hint relays were already tried:** "The event wasn't found in the database or on the suggested relays. Expanding the search to additional relays."
- **When no relay hints existed:** "The event is not in our database. Querying the Nostr network — this may take a few seconds."
- **After 6 seconds:** A slow-search notice appears: "Still searching… This is taking longer than usual."
- **After 30 seconds (timeout):** One reload attempt, then "Event not found" with retry/home buttons.

### Relay Priority

The async handler (`FetchEventFromRelaysHandler`) uses `NostrClient::getEventByNaddr()` which resolves relays via `RelaySetFactory::forAuthorWithFallback()`:

1. **Hint relays** — relays embedded in the naddr TLV data (highest priority).
2. **Author's NIP-65 relay list** — the author's kind 10002 event.
3. **Content relays** — configured relay fallbacks.

## Search Forms Using This Feature

- **Search page** — `SearchComponent.html.twig` (Live Component form)
- **Discover page** — `pages/discover.html.twig`
- **User search page** — `user_search/search.html.twig`

## Related Files

| File | Purpose |
|------|---------|
| `assets/controllers/search/nostr_redirect_controller.js` | Client-side naddr detection, decoding, validation, feedback |
| `assets/controllers/content/event_fetch_controller.js` | Loading page polling, Mercure SSE, phased progress |
| `assets/typescript/nostr-utils.ts` | NIP-19 bech32 decoding (TLV parsing) |
| `templates/event/loading.html.twig` | Event loading page with phased messages |
| `templates/components/SearchComponent.html.twig` | Search page form with nostr-redirect wiring |
| `src/Controller/EventController.php` | Server-side naddr routing and async dispatch |
| `src/MessageHandler/FetchEventFromRelaysHandler.php` | Async relay fetch worker |
| `assets/styles/03-components/search.css` | Styles for inline status messages and slow notice |

