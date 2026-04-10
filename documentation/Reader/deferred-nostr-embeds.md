# Deferred Nostr Embeds

## Overview

When an article references other Nostr events via `nostr:nevent1…`, `nostr:note1…`, or `nostr:naddr1…` URIs, the Converter resolves what it can from the local database and Redis cache. References that cannot be resolved locally are emitted as **deferred embed placeholders** — lightweight HTML divs that get resolved later.

## Architecture

### Three-phase resolution

```
Phase 1 — Conversion time (Converter)
  ├─ Event found in local DB → rich card HTML (event_card, Molecules/Card)
  └─ Event NOT found         → <div class="nostr-deferred-embed" ...></div>

Phase 2 — Template render time (resolve_nostr_embeds → Molecules:NostrEmbed)
  ├─ Event now in DB/Redis → rich card HTML (same templates)
  └─ Still missing         → placeholder with Stimulus controller

Phase 3 — Client-side (nostr--deferred-embed Stimulus controller)
  └─ Calls /api/preview/ to fetch and render the event
```

### Why deferred?

Previously, the Converter made synchronous relay network calls to resolve every `nostr:` reference. This caused:
- **Batch processing** (`articles:process-html`) to take minutes due to 10s+ relay timeouts
- **On-the-fly rendering** to stall when relays were slow
- **Unsaveable HTML** when relay fetches failed (articles were skipped)

Now the Converter never touches the network. Relay fetching is handled exclusively by background workers, and resolution happens from local data at render time.

## Deferred embed HTML format

```html
<div class="nostr-deferred-embed"
     data-nostr-bech="nevent1…"
     data-nostr-type="nevent"></div>
```

This is what gets saved in `processed_html`. It's valid, inert HTML.

## Twig filter: `resolve_nostr_embeds`

Applied in templates where article content is rendered:

```twig
{{ content|resolve_nostr_embeds|raw }}
```

The filter (`NostrEmbedRuntime::resolveEmbeds()`) regex-scans the HTML for
`nostr-deferred-embed` divs and renders the `Molecules:NostrEmbed` Twig
component for each one via `ComponentRendererInterface::createAndRender()`.

All DB/Redis lookups and rendering logic live in the component's `mount()`
method — the runtime is just a thin regex→component bridge.

### Templates using the filter
- `templates/pages/article.html.twig`
- `templates/magazine/chapter.html.twig`
- `templates/magazine/read.html.twig`
- `templates/components/Atoms/Content.html.twig`

## Molecules:NostrEmbed component

**PHP class:** `src/Twig/Components/Molecules/NostrEmbed.php`
**Template:** `templates/components/Molecules/NostrEmbed.html.twig`

Props: `bech` (string), `type` (string — note, nevent, naddr)

On `mount()`, the component:
1. Decodes the bech32 reference
2. Looks up the event in `EventRepository` (for note/nevent) or `ArticleRepository` (for naddr longform)
3. Fetches author metadata from `RedisCacheService`
4. Sets `resolved = true` and populates `event`/`article`/`authorMeta` if found

The template then renders:
- A `Molecules:Card` for resolved longform articles
- A `_kind20_picture` partial for resolved kind 20 pictures
- An `event_card` partial for other resolved events
- A Stimulus-powered placeholder for unresolved references

## Client-side fallback

The `nostr--deferred-embed` Stimulus controller (`assets/controllers/nostr/deferred_embed_controller.js`) automatically calls `/api/preview/` on connect to attempt resolution for embeds that couldn't be resolved server-side.

## Files

| File | Role |
|------|------|
| `src/Twig/Components/Molecules/NostrEmbed.php` | Component: DB/Redis lookup + render logic |
| `templates/components/Molecules/NostrEmbed.html.twig` | Component template: card or placeholder |
| `src/Twig/NostrEmbedExtension.php` | Registers the `resolve_nostr_embeds` filter |
| `src/Twig/NostrEmbedRuntime.php` | Thin bridge: regex → component render |
| `src/Util/CommonMark/Converter.php` | Emits deferred embed divs; no relay calls |
| `src/Util/CommonMark/NostrSchemeExtension/NostrSchemeParser.php` | Simplified: only uses prefetched data, falls through for unresolved |
| `assets/controllers/nostr/deferred_embed_controller.js` | Client-side fallback via `/api/preview/` |
