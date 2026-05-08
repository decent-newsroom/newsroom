# Comments in UnfoldBundle

## Overview

Comments (kind 1111) and zap receipts (kind 9735) are now displayed on UnfoldBundle post pages.

## Architecture

| Component | File |
|-----------|------|
| Context builder | `src/UnfoldBundle/Theme/ContextBuilder.php` |
| Post template | `src/UnfoldBundle/Resources/themes/default/post.hbs` |

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
  content: string,       // Comment text or zap message
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
- Comment content (plain text, no markdown processing)
- Zaps highlighted with a gold left border and lightning icon

## Limitations

Currently, UnfoldBundle comments are **read-only** (display only, no UI for publishing comments).

Compare with the main app (`src/Twig/Components/Organisms/Comments.php`), which provides:
- Real-time Mercure updates
- Comment form with NIP-07/NIP-46 signing
- Nested reply structure (NIP-22)
- Link parsing and embeds
- Full zap UI

Future enhancements could add these features to UnfoldBundle if needed.

