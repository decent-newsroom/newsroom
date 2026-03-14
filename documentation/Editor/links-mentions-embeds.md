# Links, Mentions and Embeds in Articles

## Overview

This feature adds support for links, mentions and embeds in the article editor, following the Nostr protocol specifications (NIP-19, NIP-21, NIP-27).

## Components

### 1. Quill Link Fix

**Problem:** The Quill Snow theme link tooltip was clipped by `overflow: hidden` on `.ql-container`, making it impossible to insert links.

**Solution:** Added CSS rules in `editor-layout.css` to reposition the tooltip as `position: fixed` centered in the viewport, with a high `z-index`.

### 2. Mentions Tab (Left Sidebar)

**File:** `assets/controllers/editor/mentions_controller.js`

A new "Mentions" tab in the editor's left sidebar allows searching for users via the existing `/api/users/search` API. Clicking a search result inserts `nostr:npub1…` at the Quill cursor position. The text is automatically highlighted with a purple background by the existing Quill highlighting logic.

**NIP-27 compliance:** The `p` tag for each mentioned pubkey is auto-generated at publish time.

### 3. Embeds Tab (Left Sidebar)

**File:** `assets/controllers/editor/embeds_controller.js`

A new "Embeds" tab in the editor's left sidebar provides three sections:

- **Profile embed:** Search users → insert `nostr:npub1…` on its own line (renders as a profile card)
- **Article embed:** Search articles → insert `nostr:naddr1…` (renders as an article preview card)
- **Raw paste:** Accept any NIP-19 identifier (`note1`, `nevent1`, `naddr1`, `npub1`, `nprofile1`)

### 4. Auto-tagging (NIP-27)

When publishing an article, the content is scanned for all `nostr:` references and the appropriate tags are auto-generated:

| Reference Type | Generated Tag |
|---|---|
| `nostr:npub1…` | `['p', hex_pubkey]` |
| `nostr:nprofile1…` | `['p', hex_pubkey, relay]` |
| `nostr:note1…` | `['e', event_id, '', 'mention']` |
| `nostr:nevent1…` | `['e', event_id, relay, 'mention']` + `['p', author_hex]` |
| `nostr:naddr1…` | `['a', 'kind:pubkey:d-tag', relay]` + `['p', author_hex]` |

Tags are deduplicated — the same pubkey or event referenced multiple times generates only one tag.

This scanning happens both:
- **Client-side** in `nostr_publish_controller.js` via `extractNostrTags()` 
- **Server-side** in `NostrEventBuilder::extractNostrReferenceTags()` (as a safety net)

### 5. NIP-19 Utilities

**File:** `assets/typescript/nostr-utils.ts`

New utilities added:
- `decodeNip19(bech32str)` — Decode any NIP-19 entity (npub, note, nprofile, nevent, naddr)
- `encodeNprofile(pubkeyHex, relays?)` — Encode an nprofile bech32 string
- `encodeNaddr(kind, pubkeyHex, identifier, relays?)` — Encode an naddr bech32 string
- `extractNostrTags(content)` — Scan text for nostr: references and return p/e/a tags

Also added internal helpers: `bech32Encode()`, `hexToBytes()`, `parseTLV()`, `buildTLV()`.

### 6. Rendering

No changes needed for rendering. The existing `Converter::processNostrLinks()` and `NostrSchemeParser` already handle:
- `nostr:npub1…` → profile mention links
- `nostr:nprofile1…` → profile mention links  
- `nostr:note1…` / `nostr:nevent1…` → event cards
- `nostr:naddr1…` → article preview cards

## Files Changed

| File | Change |
|---|---|
| `assets/styles/editor-layout.css` | Quill link tooltip fix + mention/embed panel styles |
| `assets/typescript/nostr-utils.ts` | NIP-19 TLV encode/decode, bech32 encode, extractNostrTags |
| `assets/controllers/editor/mentions_controller.js` | **New** — Mentions panel Stimulus controller |
| `assets/controllers/editor/embeds_controller.js` | **New** — Embeds panel Stimulus controller |
| `assets/controllers/nostr/nostr_publish_controller.js` | Import & call extractNostrTags for auto-tagging |
| `src/Service/Nostr/NostrEventBuilder.php` | Server-side extractNostrReferenceTags + call from buildTags |
| `templates/editor/panels/_articlelist.html.twig` | Added Mentions & Embeds tabs and panel markup |
| `CHANGELOG.md` | Added feature entries under v0.0.15 |

## References

- [NIP-19](../documentation/NIP/19.md) — bech32-encoded entities (npub, note, nprofile, nevent, naddr)
- [NIP-21](../documentation/NIP/21.md) — `nostr:` URI scheme
- [NIP-27](../documentation/NIP/27.md) — Text note references (mentions in content)

