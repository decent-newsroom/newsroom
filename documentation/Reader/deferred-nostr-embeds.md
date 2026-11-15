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
  ├─ Readable local Event  → compact shared Event Workspace preview
  ├─ Restricted/error      → non-fetching restricted state
  └─ Still missing         → placeholder with Stimulus controller

Phase 2b — Async bulk prefetch (PrefetchNostrEmbedsMessage → worker)
  └─ ArticleController dispatches a Messenger message with all placeholder refs
     → PrefetchNostrEmbedsHandler batch-fetches by ID + coordinate from relays
     → Projects via GenericEventProjector (+ ArticleEventProjector for longform)
     → Next page view renders the embeds fully in Phase 1/2

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

The component's `mount()` performs the local event and policy lookups; the
runtime is just a thin regex→component bridge.

### Templates using the filter
- `templates/pages/article.html.twig`
- `templates/magazine/chapter.html.twig`
- `templates/magazine/read.html.twig`
- `templates/components/Atoms/Content.html.twig`

## Molecules:NostrEmbed component

**PHP class:** `src/Twig/Components/Molecules/NostrEmbed.php`
**Template:** `templates/components/Molecules/NostrEmbed.html.twig`

Props: `bech` (string), `type` (string — note, nevent, naddr)

On `mount()`, `note`/`nevent` resolve an exact Event ID; `naddr` uses the
existing local coordinate lookup. Candidate kind, author, and first signed
`d` must match the address; a malformed first `d` is never replaced by a
later one. Exact references never fall forward to a coordinate revision.

Readable local Event kinds `1`, `20`, `21`, `22`, `30040`, `30041`, and
`30818` use `EventPresentationFactory` and the shared `EventPreview`. Kind
`30023` additionally requires an Article projection for that **exact Event
ID**, with matching identity and Essayist-exclusive entitlement. A local
longform event without that evidence is restricted. If its local Event row is
absent, the component may use the complete raw signed event envelope from that
exact Article projection after projection identity, timestamp, author
signature and event-ID verification, suppression, tombstone, hidden-coordinate,
mute, and entitlement checks. It builds the snapshot from raw signed
tags/content without fabricating an Event, rendering processed HTML, or
fetching or persisting data. Missing or inconsistent raw fields remain
restricted and never fall through to deferred fetch. Drafts, encrypted and
unclassified kinds are restricted until their scope can be established. This
is intentionally a bounded allowlist.

Every displayed Event passes author suppression, NIP-09 tombstone, hidden
coordinate, and authenticated strict-mute checks. A policy or lookup failure
is logged and fails closed; denied/failed targets do not mount the client
fallback. Signed warning evidence on either the Event or the matching
Article projection results in a warning-only fragment, without title,
description, identity, media, warning reason, or link. Ordinary previews are
compact: they do not include the old card's full body, media, or profile.
Article and chapter coordinate links keep their focused routes; other events
use `/e/`, with exact references staying exact. No specialist card or profile
component is mounted; this path does not queue relay work, persist data,
resolve nested references, or fetch metadata.

Genuinely missing targets keep the previous Stimulus placeholder and API
fallback. The exact-reference API now enforces equivalent kind, projection,
entitlement, visibility, and warning gates; a placeholder is not a grant of
access. Addressable API specialists retain their separate behavior below.

## NIP-22 comment context

The single-event kind `1111` template renders the first lowercase `a` parent
and first uppercase root reference (`A`, `E`, or `I`) through
`Molecules:NostrEmbed` where applicable, using the
shared compact preview for locally available, authorized addressable events.
The source coordinate and its relay hint are encoded with the existing NIP-19
service; first-tag order and the existing equal-parent/root suppression remain
unchanged. The preview retains the normal addressable route selection. Missing
local targets keep the component's deferred placeholder, but server rendering
does not synchronously fetch or persist an event. External `i` references and
the comment body are unchanged.

The separate comment-as-card feed partial, currently reached by the
member-only Essayist activity tab through `_bookmark_event_card`, now also
uses the local shared embed for its chosen `A`/`a` address. It keeps the first
root over the first parent, ahead of the existing event and external branches,
and retains the comment callout and enclosing card. Unlike the single-event
template, this feed partial did not retain relay hints, and still does not.
The address filter checks encoding and a full identity round trip before
mounting an embed; invalid or unencodable coordinates show the translated
restricted state without a deferred request or fallback to another reference.
Root selection is across reference types (`A`, `E`, or `I`) before the existing
address/event/external parent fallback priority. Malformed root values never
fall through to a parent. External identities are clickable only when they
are valid HTTP(S) URLs; other values remain escaped text.
The installed NIP-19 encoder cannot faithfully encode non-ASCII identifiers,
so those fail closed rather than changing identity. The legacy coordinate
filter and other callers are unchanged.

Cold valid addresses use the existing deferred placeholder and DB-only preview
API; specialist API rendering and explicit navigation retain their existing
behavior. Initial rendering does not fetch from relays or persist targets. This
slice does not migrate the surrounding card, event/external branches, signed
bookmark actions, or magazine-specific coordinate cards.

## Client-side fallback

The `nostr--deferred-embed` Stimulus controller (`assets/controllers/nostr/deferred_embed_controller.js`) automatically calls `/api/preview/` on connect to attempt resolution for embeds that couldn't be resolved server-side.

The endpoint's exact `note`/`nevent` fallback accepts the current browser
payload: `{identifier: bech, type, decoded: {bech, type}}`. It decodes Bech32
server-side, checks the checksum, reference type, matching inner/outer raw
identifier, and 32-byte event ID. A supplied decoded ID cannot override a raw
reference; nevent author/kind/relay hints cannot redirect lookup or grant access.
Legacy decoded `id` / index `0` payloads (including JSON-string `decoded`) remain
supported with 64-hex validation and lowercase lookup. Malformed references use
the existing invalid-identifier warning response; the controller retains its
placeholder/link on warning or failed responses.

Only the referenced exact Event ID is read from the local Event store, never a
newer coordinate revision or an Article-only fallback. Before shared presentation,
the API applies admin/permanent author suppression, NIP-09 tombstones, hidden
coordinates, and authenticated strict NIP-51 mutes. It uses the same public-kind
allowlist as local `NostrEmbed`; unknown, encrypted, draft, and otherwise scoped
kinds are denied. Kind `30023` additionally requires its exact Article projection,
matching event ID, kind, author, slug and first signed raw `d`, complete projection
identity, and Essayist-exclusive entitlement. A malformed first Event `d` is
denied rather than replaced by a later duplicate. Lookup failures fail closed.

Signed warning evidence from either the Event or the authorized matching Article
returns the existing translated warning-only fragment, without identity, title,
description, media, warning reason, link, or presentation/Article template props.
Denied or missing exact events retain the existing successful local-missing HTML
fragment. The browser's replacement behavior is unchanged: successful non-warning
HTML replaces only that occurrence, including local-missing and warning-only
fragments. Readable public previews remain available anonymously. The endpoint
does not fetch, persist, convert bodies, or resolve nested references.

Addressable `naddr` previews keep their specialist article, chapter, and wiki
paths; this bounded exact-reference parity slice does not change those
coordinate/latest-version semantics.

For `naddr`, kind `30023` retains the Article projection/Card but requires a
complete matching projection identity (event ID, author, kind, slug, and
timestamp) before exposure. It checks author suppression, Essayist-exclusive
entitlement, hidden coordinates, NIP-09 tombstones, and authenticated strict
NIP-51 mutes. Kinds `30041` and `30818` retain their ChapterCard/WikiPreview
only after Event visibility checks. A local kind `30040` with no specialist
preview uses the shared generic Event presentation; other unsupported kinds
remain unsupported. Cold Article/chapter/wiki fetch/view links are unchanged.
No naddr relay hints are used for synchronous fetches or persistence.

For Article projections, the first `d` in the raw signed tags must also be a
string exactly equal to the requested identifier, independently of the projected
slug (which ingestion may overwrite when duplicate tags exist). Missing raw
tags, missing/malformed first `d`, and mismatches deny before rendering or policy
lookups; a later matching `d` cannot rescue them. An explicit `['d', '']` matches
an empty identifier, but an absent `d` does not.

The API selects Article revisions by `createdAt DESC, eventId ASC`. Before
rendering ArticlePreview/Card, authoritative raw-tag warning evidence (including
NIP-36 warnings, NIP-32 sensitive labels, and sensitive hashtags) replaces it
with a translated warning-only fragment: no title, summary, image, warning reason,
or Article object is passed to that template. Derived flags cannot clear raw
warning evidence.

Local Event candidates are independently checked against the requested kind,
author, and **first** `d` tag before either specialist or generic rendering;
a later matching `d` cannot rescue a conflicting or malformed first one.
`EventRepository::findByNaddr` retains its indexed lookup and legacy JSONB
fallback, but the fallback now matches only the first `d`. Both query branches
use `created_at DESC, id ASC`, matching the current-version tie-break rule.
These repository corrections also apply to its existing callers; no
coordinate-card or event-page rendering was migrated in that API slice.

## Remaining boundaries

Conversion-time cards already generated by the Converter bypass
`NostrEmbed`; their authorization and warning handling are not changed here.
The documented exact-reference client/API payload and access gap is closed.
Addressable `naddr` API specialists and profile previews remain separate paths;
this slice does not claim full local-component/API parity for them or migrate
conversion-time cards. The coordinate
repository checks its indexed `d_tag` branch before its legacy JSON fallback,
so mixed backfilled and unbackfilled revisions are not globally compared.
The new component preserves that existing selection behavior and independently
verifies the selected signed identity. Live browser/worker integration has not
been validated as part of this slice.

## Exact-reference parity validation

Targeted Docker validation passes **203 PHPUnit tests / 1,364 assertions**
across the exact API, existing preview controller, local NostrEmbed, access
policy, shared EventPreview, and deferred runtime suites. The exact API tests
exercise the browser and legacy payloads, malformed/conflicting identities,
kind scope, exact revision/projection selection, entitlement, warnings from
both sources, policy denials, and lookup failures, with real Twig fragments.
Repository mocks forbid coordinate fallback and persistence.

The existing controller is unchanged; **4 Node tests** in an isolated Docker
Node image characterize its payload, per-occurrence replacement, and existing
unresolved/error handling. PHP syntax and the two reused API Twig templates
also pass lint. These tests are not a live browser, firewall/session, relay
worker, or end-to-end database validation.

## Files

| File | Role |
|------|------|
| `src/Twig/Components/Molecules/NostrEmbed.php` | Component: local resolution, policy, and shared presentation |
| `src/Controller/DefaultController.php` | `/api/preview/`: exact-reference decoding, local lookup, and guarded fragments |
| `src/Service/EventWorkspace/EventPreviewAccessPolicy.php` | Shared visibility/Article entitlement policy and public embed kind allowlist |
| `templates/components/Molecules/NostrEmbed.html.twig` | Component template: compact preview, restricted state, or placeholder |
| `src/Twig/NostrEmbedExtension.php` | Registers the `resolve_nostr_embeds` filter |
| `src/Twig/NostrEmbedRuntime.php` | Thin bridge: regex → component render |
| `src/Util/CommonMark/Converter.php` | Emits deferred embed divs; no relay calls |
| `src/Util/CommonMark/NostrSchemeExtension/NostrSchemeParser.php` | Simplified: only uses prefetched data, falls through for unresolved |
| `assets/controllers/nostr/deferred_embed_controller.js` | Client-side fallback via `/api/preview/` |
| `tests/Unit/Controller/DefaultControllerExactPreviewTest.php` | Exact-reference payload, scope, entitlement, warning/privacy, and failure coverage |
| `tests/JavaScript/deferred-embed.test.mjs` | Current browser payload and placeholder/fragment behavior |
| `src/Service/EmbedReferenceExtractor.php` | Parses processed HTML for deferred embed placeholders → event IDs + coordinates |
| `src/Message/PrefetchNostrEmbedsMessage.php` | Message carrying unresolved embed refs |
| `src/MessageHandler/PrefetchNostrEmbedsHandler.php` | Worker: batch relay fetch + projection |
