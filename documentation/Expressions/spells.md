# Spells (NIP-A7, kind 777)

First-class support for Nostr **spells** — portable, shareable query filters
defined by [NIP-A7](../NIP/A7.md). A spell is a kind:777 event that describes
a relay REQ filter (kinds, authors, tags, since/until, limit, relays, search),
optionally with variables such as `$me` and `$contacts` that are resolved
against the viewer's runtime context.

## What this feature adds

1. **Spell hydration worker** — a new long-lived relay subscription that
   projects kind:777 events from the local strfry relay into the `event`
   table. Spells are regular (non-replaceable) events, so no dedup / d-tag
   bookkeeping is required.
2. **Spell listing** at `/spells` — an Organism component that renders all
   known spells with their name, description, queried kinds, topics, and
   author. Each card links to the spell's feed page.
3. **Spell feed page** at `/spell/{nevent}` — evaluates the spell against the
   signed-in viewer's context and renders the resulting events via the shared
   `partial/_bookmark_event_card.html.twig` dispatcher (same as the
   expression results page). Results are cached in Redis per
   `(eventId, created_at, viewer pubkey, contacts+interests hash)`; a cold
   cache dispatches `EvaluateSpellMessage` to the `async_expressions`
   transport and the page shows a Mercure-driven loading state that
   auto-reloads when evaluation finishes.
4. **Click-to-use spell picker** in the expression builder
   (`/expressions/create`, `/expressions/edit/...`). Every filter / set /
   traversal stage now offers a `+ Spell` button next to `+ Input` /
   `+ Clause`. Clicking it opens a modal fed by `GET /api/spells`, with a
   search box and per-spell cards showing name, description, kinds, topics,
   and author. Selecting a spell appends an `["input","e",<event-id>]` clause
   to the stage — the same shape the expression engine uses for any event-id
   input, so the spell plugs in with no parser changes.

## Addressing

Spells are addressed by **event id (`nevent`)**, never by `naddr`, because
kind:777 is a regular event. The URL for a spell's feed page is
`/spell/nevent1…` and the picker inserts the raw 64-char event id into the
expression's `input` clause (the existing engine path handles `nevent`/`note`
decoding when needed).

## Authentication

Spell execution **requires a logged-in viewer**. Per NIP-A7, clients MUST
NOT send a REQ when `$me` / `$contacts` can't be resolved, and the feed
cache key includes the viewer's pubkey and context hash so results are never
shared across users. Anonymous visitors to `/spell/{nevent}` see a login
prompt instead of results.

## Architecture

| Component | Path |
|-----------|------|
| Hydration worker | `src/Command/SubscribeLocalSpellsCommand.php` |
| Worker manager | `src/Command/RunRelayWorkersCommand.php` (subprocess `spells`, disable with `--without-spells`) |
| Controller | `src/Controller/Newsroom/SpellController.php` (`/spells`, `/spell/{nevent}`, `/api/spells`) |
| Twig Organism | `src/Twig/Components/Organisms/SpellList.php` + `templates/components/Organisms/SpellList.html.twig` |
| Feed templates | `templates/spells/{index,view,view_loading}.html.twig` |
| Async eval | `src/Message/EvaluateSpellMessage.php` + `src/MessageHandler/EvaluateSpellHandler.php` → `async_expressions` transport |
| Execution | `ExpressionService::evaluateSpell / evaluateSpellCached / buildSpellCacheKey / getCachedSpellResults` → `SpellSourceResolver::executeEvent` |
| Picker UI | `assets/controllers/nostr/nostr_expression_controller.js` (`pickSpell`, `+ Spell` button) |
| Picker styles | `assets/styles/03-components/spell-picker.css` |
| Mercure topic | `/spell-eval/{cacheKey}` (reuses `content--expression-feed` Stimulus controller) |

## Commands

Run inside the Docker container:

```bash
# Standalone: just the spell hydration daemon
docker compose exec php bin/console spells:subscribe-local-relay -vv

# Runs automatically as a subprocess of the worker-relay service via
# app:run-relay-workers — restart worker-relay after merging this feature:
docker compose restart worker-relay

# Rebuild assets after picker changes
docker compose exec php bin/console asset-map:compile
```

## Notes & deferrals

* **Spell creator UI** (publishing a kind:777 from this app) is deferred.
  Today, users can still browse and execute spells published anywhere on
  the network as long as the spell reaches the local relay.
* **Feed = REQ execution**, not "events that reference this spell." The
  latter is a plausible follow-up (e.g. a comments/discussion section on a
  spell's page) but out of scope here.
* The loading page reuses `content--expression-feed` Stimulus controller
  verbatim; only the Mercure topic prefix (`/spell-eval/…` vs
  `/expression-eval/…`) differs.

