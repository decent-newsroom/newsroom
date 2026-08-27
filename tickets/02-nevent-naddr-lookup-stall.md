# Ticket 02 — nevent/naddr lookup: page stalls and renders only the not-found fallback

**Severity:** High — direct event lookup (search by NIP-19 identifier) appears broken to users.
**Area:** Search / event resolution (`/e/{nevent}`)

## Problem statement

When a user searches for an event by `nevent`/`naddr`, the identifier is decoded successfully and a
relay lookup is (apparently) performed, but the page stalls on the loading screen and eventually
shows only the not-found fallback — even in cases where the event exists on relays. Either the
fetched event is not making it to the render, or the async pipeline never completes/notifies.

## Current implementation map (full flow)

1. **Search box (client)** — `assets/controllers/search/nostr_redirect_controller.js`
   - `submit()` (~lines 11–44): strips `nostr:` prefix, detects `npub|naddr|nevent|note|nprofile`,
     decodes via `decodeNip19()` from `assets/typescript/nostr-utils.ts` (TLV parse incl. relay
     hints), then redirects to `/e/{identifier}` (~line 57).
2. **Server route** — `src/Controller/EventController.php` (~lines 204–644), route
   `#[Route('/e/{nevent}', name: 'nevent', requirements: ['nevent' => '^(nevent|note|naddr|nprofile)1.*'])]`,
   decodes with `new Bech32($nevent)` (~line 216).
3. **nevent branch** (~lines 278–359): DB lookup `EventRepository::findById()`; on miss, enrich
   relays with author's cached/DB relay list (~lines 298–317), then **synchronous**
   `NostrClient::getEventById($eventId, $relays)` (~line 326; 2s direct / 3s gateway timeouts). On
   success: project via `GenericEventProjector`, persist, render. On failure: render
   `templates/event/loading.html.twig` with `lookupKey='nevent:{id}'` and dispatch
   `FetchEventFromRelaysMessage`.
4. **naddr branch** (~lines 361–563): kind 30023 redirects to article route (~lines 373–390); DB
   lookup `EventRepository::findByNaddr()` (~line 409); on miss, synchronous
   `NostrClient::getEventByNaddr(..., allowRelayListNetworkFetch: false)` (~line 458); on failure,
   loading page with `lookupKey='naddr:{kind}:{pubkey}:{identifier}'` + async message.
5. **Async handler** — `src/MessageHandler/FetchEventFromRelaysHandler.php`: DB race guard
   (~lines 40–49); relay enrichment via `UserRelayListService::getRelaysForFetching()` **for
   nevent/note only** (~lines 62–82); fetch (~lines 87–97); Mercure publish `status=found|not_found`
   on topic `/event-fetch/{lookupKey}` (~lines 105–148).
6. **Loading page (client)** — `templates/event/loading.html.twig` +
   `assets/controllers/content/event_fetch_controller.js`: subscribes to the Mercure topic,
   timeout 30s prod / 120s dev; `_onMessage()` reloads on `found`, shows not-found on `not_found`;
   `_onTimeout()` reloads once (sessionStorage flag), second time shows not-found.
7. **WebSocket layer** — `src/Util/NostrPhp/TweakedRequest.php` `send()` (~lines 81–187): blocking
   `while ($resp = $client->receive())` loop, terminates on EOSE, matched `stopOnEventId`, or
   socket timeout (default 3s, ~line 26).

## Identified failure/stall points (ranked)

### F1 — naddr async fetch never enriches relays (high confidence, matches symptom)

`FetchEventFromRelaysHandler` (~lines 87–97): for **naddr** lookups it calls `getEventByNaddr()`
with only the original TLV hint relays — unlike the nevent/note path, there is **no enrichment**
with the author's NIP-65 relay list. Many naddr identifiers carry zero or stale relay hints, so the
async fetch queries the wrong relays, finds nothing, and publishes `not_found`. Users see:
decode OK → loading page → not-found fallback. **Fix:** enrich naddr fetches with
`UserRelayListService::getRelaysForFetching($pubkey)` exactly like the nevent path (the author
pubkey is always known for naddr), and pass `allowRelayListNetworkFetch: true` in the async context
(it's a worker, blocking is fine there).

### F2 — timeouts too aggressive for the sync fast-path (contributes to "always falls to async")

`NostrClient` constants (~lines 26–28): `EVENT_LOOKUP_DIRECT_TIMEOUT = 2s`,
`EVENT_LOOKUP_GATEWAY_TIMEOUT = 3s`; `TweakedRequest` default 3s. Slow relays cannot answer before
the socket timeout, so the sync path nearly always fails and everything funnels into the (broken
per F1) async path. **Fix:** keep the sync path short (it blocks an HTTP request — that's by
design), but make the timeouts configurable via env/parameters and give the **async handler**
generous timeouts (e.g. 10–15s) since it runs in a worker.

### F3 — event found but lost: projection failures are swallowed

`EventController` (~lines 242–249, 328–331, 466–481): when the relay returns an event but
`GenericEventProjector::projectEventFromNostrEvent()` throws, the exception is caught, logged as a
warning, and the flow falls through to the loading page — the fetched event is discarded. The async
handler then repeats the same projection and fails the same way → guaranteed not-found despite the
event existing. **Fix:** distinguish "not found on relays" from "found but failed to
project/persist"; on projection failure, log at error level with the event id and render the event
directly from the raw relay payload if possible (or at minimum publish a distinct Mercure status so
the UI can show a real error instead of not-found). Also audit `FetchEventFromRelaysHandler`
(~lines 113–148) for the same swallow pattern.

### F4 — naddr DB-hit control flow falls through to redirects

`EventController` naddr branch (~lines 410–445): on DB hit `$event` is set but execution continues
into article/curation redirect checks (~lines 521–562). If the event is an article kind whose
`Article` projection is missing, the code may attempt an article redirect that 404s, or take an
unintended branch. Works "by accident" today per exploration, but is fragile and a plausible
contributor for specific kinds. **Fix:** restructure with explicit early returns per outcome; add a
regression test for "naddr of non-article kind found in DB renders the generic event page".

### F5 — Mercure dependency: if the publish or subscription fails, UI waits full timeout then claims not-found

`event_fetch_controller.js` (~lines 32–48, 100–112): if the hub URL is missing/misconfigured or the
SSE connection drops, the only recovery is the 30s timeout → reload → second-attempt not-found.
Meanwhile the handler may have succeeded and persisted the event; the reload should then hit the DB
— **verify the reload actually re-runs the DB lookup and isn't served a cached loading page**. Also
verify `FetchEventFromRelaysHandler` publishes to exactly the topic the loading page subscribed to
(compare lookupKey construction character-for-character on both sides — `naddr:{kind}:{pubkey}:{identifier}`
vs the controller's key; any mismatch means the browser never hears the result).

### F6 — hint-relay handling

`NostrClient::getEventById()` (~lines 122–128) caps at 5 relays (hints → local → content).
`getEventByNaddr()` hint-only mode (~lines 238–254) uses a 15s timeout but only when hints exist.
Empty/malformed TLV hints silently degrade to content relays. **Fix (minor):** log the final relay
set per lookup at info level so stalls are diagnosable in production.

## Implementation plan

1. **Async naddr enrichment (F1)** — `FetchEventFromRelaysHandler`: for naddr lookup keys, merge
   TLV hint relays + `UserRelayListService::getRelaysForFetching($authorPubkey)` + content relays;
   call `getEventByNaddr(..., allowRelayListNetworkFetch: true)`. Mirror the nevent enrichment
   block (~lines 62–82).
2. **Projection error handling (F3)** — in both `EventController` sync fast-path and the async
   handler: catch projection exceptions separately from fetch failures; publish Mercure
   `status=error` (new) or at minimum log at error with event id/kind; never let a projection
   failure masquerade as not-found. Update `event_fetch_controller.js` `_onMessage()` to handle the
   new status (show an error state distinct from not-found).
3. **Topic/lookupKey parity check (F5)** — add a single source of truth for lookup keys (small
   helper, e.g. `EventLookupKey::forNevent(string $id)` / `forNaddr(int $kind, string $pk, string $id)`)
   used by both `EventController` and `FetchEventFromRelaysHandler`; unit-test it.
4. **Control flow cleanup (F4)** — refactor the naddr DB-hit branch to explicit
   `return $this->render(...)` / `return $this->redirect(...)` per outcome.
5. **Timeout configuration (F2)** — promote the hardcoded 2s/3s constants to service parameters
   (env-overridable, defaults unchanged for the HTTP path); async handler uses larger values.
6. **Diagnosability (F6)** — info-log the resolved relay set and outcome (`found@relay`,
   `eose-empty`, `timeout`) per lookup in `NostrClient::getEventById/getEventByNaddr`.

## Guardrails

- Do not raise HTTP-request-blocking timeouts significantly; the fix is to make the **async** path
  reliable, not the sync path slower.
- Do not perform NIP-65 network discovery inside the HTTP request (keep
  `allowRelayListNetworkFetch: false` there) — only in the worker.
- Keep the existing `not_found` UX for genuinely missing events; the fix must not spin forever.

## Acceptance criteria

- [ ] Searching an `naddr` (non-30023 kind, no relay hints, author has a known NIP-65 list, event
      exists on the author's write relays) renders the event: fast path if relays are quick,
      otherwise loading page → Mercure update → event rendered without manual refresh.
- [ ] Searching an `nevent` with valid relay hints renders the event.
- [ ] A genuinely nonexistent identifier shows the not-found fallback within the timeout.
- [ ] A projection failure surfaces as an error state (logs + UI), not as not-found.
- [ ] Unit tests: lookup-key helper parity; handler naddr enrichment (mock
      `UserRelayListService`); handler publishes `found` / `not_found` / `error` correctly.
- [ ] `docker compose exec php bin/phpunit` green.

## Testing notes

- Reproduce with a known-good naddr from an author whose events are not on the instance's content
  relays (this isolates F1).
- Watch worker logs: `docker compose logs -f worker` while performing the lookup; confirm the
  handler runs, which relays it queries, and what it publishes to Mercure.
- Browser devtools → EventSource: confirm the Mercure subscription URL topic matches the topic the
  handler publishes to.

## Documentation & changelog

- Update `documentation/` (search/event lookup doc if present, else create one) describing the
  lookup pipeline: decode → DB → sync fast-path → async + Mercure, including the lookup-key
  convention.
- Changelog entry: "Fix: nevent/naddr lookup stalling on not-found — async naddr fetches now use
  author relay lists; projection failures no longer reported as not-found."
