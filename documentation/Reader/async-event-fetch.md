# Async Event Fetching

## Problem

The `/e/naddr1…`, `/e/nevent1…`, `/e/note1…`, and `/article/naddr1…` routes used to
fetch events **synchronously** from Nostr relays when the event was not found in
the local database. Each relay round-trip can take several seconds, and with
only 4 FrankenPHP workers, slow relays would block the entire application,
resulting in `Maximum execution time of 15 seconds exceeded` errors and 504
gateway timeouts.

## Solution

Relay fetching is now **asynchronous**. When an event is not found locally:

1. A `FetchEventFromRelaysMessage` is dispatched to the `async` Messenger
   transport.
2. The controller immediately renders a **loading placeholder** page
   (`event/loading.html.twig`) with a spinner.
3. The browser subscribes to a **Mercure SSE topic** for the fetch result.
4. A background worker picks up the message, queries relays, persists the
   event via `GenericEventProjector`, and publishes the result to Mercure.
5. The browser receives the Mercure update and **reloads the page** (via
   Turbo or full reload). Since the event is now in the database, the page
   renders normally.
6. If the worker reports "not found" or nothing arrives within 30 seconds,
   the UI switches to a **"Not found on relays"** state with a **Retry**
   button.

## Components

| Component | File |
|-----------|------|
| Message DTO | `src/Message/FetchEventFromRelaysMessage.php` |
| Async handler | `src/MessageHandler/FetchEventFromRelaysHandler.php` |
| Event controller | `src/Controller/EventController.php` |
| Article controller | `src/Controller/Reader/ArticleController.php` |
| Loading template | `templates/event/loading.html.twig` |
| Stimulus controller | `assets/controllers/content/event_fetch_controller.js` |
| Messenger routing | `config/packages/messenger.yaml` (`async` transport) |

## Mercure Topic

The topic pattern is `/event-fetch/{lookupKey}` where `lookupKey` is:

- `naddr:{kind}:{pubkey}:{identifier}` for parameterized replaceable events
- `nevent:{eventId}` for events with relay hints
- `note:{eventId}` for plain notes

## Redis Status Key

The handler stores the fetch result at `event_fetch:{lookupKey}` (5-minute TTL)
so that subsequent page loads can check the status without re-dispatching.

## Fallback Behaviour

If Mercure is unavailable (hub URL missing), the Stimulus controller falls back
to a simple timed reload after 8 seconds. If the event was persisted by then,
the page renders normally; otherwise the loading template is served again and
the not-found state is shown.

## Event Types Covered

- **naddr** (NIP-33 parameterized replaceable events) — articles, curation sets, etc.
- **nevent** (NIP-01 events with relay hints)
- **note** (plain event IDs)
- **nprofile** — still a synchronous redirect (no relay fetch needed)

