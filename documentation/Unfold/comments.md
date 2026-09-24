# Comments in UnfoldBundle

## Overview

Comments (kind 1111) and zap receipts (kind 9735) are now displayed on UnfoldBundle post pages.

## Architecture

| Component | File |
|-----------|------|
| Context builder | `packages/unfold-bundle/src/Theme/ContextBuilder.php` |
| Post template | `packages/unfold-bundle/Resources/themes/default/post.hbs` |

## Implementation Details

### Data Flow

1. **Post Page Rendering**: When a post page is rendered, `ContextBuilder::buildSinglePostContext()` is called
2. **Comment Fetching**: `buildCommentsContext()` fetches comments using `EventRepository::findCommentsByCoordinate()`
3. **Author Metadata**: Author metadata (name, picture) is fetched from Redis cache in bulk
4. **Template Rendering**: Comments are passed to Handlebars template as `post.comments`

### Comment Structure

Each comment in the `post.comments` array contains:

```javascript
{
  id: string,            // Event ID
  kind: number,          // 1111 (comment) or 9735 (zap)
  pubkey: string,        // Author's hex pubkey
  content: string,       // Original comment text or zap message
  content_html: string,  // Escaped text with NIP-21 links rendered
  created_at: number,    // Unix timestamp
  created_at_formatted: string, // Human-readable date (e.g., "January 15, 2026")
  author: {
    name: string,        // Display name or pubkey short form
    pic: string|null,    // Profile picture URL
    pubkey: string       // Full hex pubkey
  },
  is_zap: boolean,       // True if kind 9735
  zap_amount: number|null, // Amount in sats (for zaps)
  zap_pubkey: string|null  // Zapper pubkey (for zaps)
}
```

### Zap Handling

Zap receipts (kind 9735) are parsed to extract:

- **Amount**: Extracted from the `description` tag's `amount` field (in millisats, converted to sats)
  - Fallback: Parse BOLT11 invoice from `bolt11` tag or `description.bolt11`
- **Zapper**: Extracted from `description.pubkey` or `P` tag

### Performance Considerations

- Comments are fetched eagerly (not lazyloaded)
- Author metadata is fetched in a single bulk query, not per-comment
- Comments are fetched from the local database only (no async relay refresh)
- No real-time updates via Mercure (as with the main app)

## Display

Comments are displayed at the bottom of post pages with:

- Author avatar and name
- Publication date
- Comment content remains plain text except for validated NIP-21 nostr: references. Profile references (npub and nprofile) become @name mentions linked to the main platform; event references (note, nevent, and naddr) become links to the main platform. Malformed references remain text with break opportunities inside long identifiers, so they cannot widen the comment layout.
- Comment text and profile names are HTML-escaped before rendering. HTML and Markdown supplied in a comment are not interpreted. Mention metadata is fetched in the same bulk lookup as comment author metadata.
- Zaps highlighted with a gold left border and lightning icon

## Limitations

Currently, UnfoldBundle comments are **read-only** (display only, no UI for publishing comments).

Compare with the main app (`src/Twig/Components/Organisms/Comments.php`), which provides:
- Real-time Mercure updates
- Comment form with NIP-07/NIP-46 signing
- Nested reply structure (NIP-22)
- Rich event embeds and the main app’s full content rendering
- Full zap UI

Future enhancements could add these features to UnfoldBundle if needed.
