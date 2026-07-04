# Features Guide

Overview of the features in Decent Newsroom and how they work together.

## Overview

Decent Newsroom provides five integrated modules:

1. **Reader** — Discover, read, and interact with articles and magazines
2. **Editor** — Write and publish long-form articles
3. **Media Manager** — Discover and manage media content
4. **Newsroom** — Create magazines, reading lists, and manage publications
5. **Search** — Full-text search across articles and users

## Reader Features

### Home Feed

The home page provides a tabbed feed for logged-in users:

- **Articles** — Combined feed from follows, interests, and discussed articles. Each article is tagged with its source (Follows, Interests, Discussed). Deduplicated by article coordinate.
- **Media** — Non-NSFW media events (pictures, videos) from followed authors and interest hashtags in a masonry grid.
- **Podcasts** — Articles from a configurable follow pack (kind 39089), managed by admin.
- **News Bots** — Articles from a configurable follow pack, managed by admin.

Anonymous users see a global latest articles feed.

### Article Reading

Articles are Nostr kind 30023 events (NIP-23 long-form content):

- Clean reading experience with typography optimization
- Markdown rendering with images, code blocks, tables, KaTeX math
- SEO-friendly structured data (JSON-LD)
- Article actions dropdown: copy link, bookmark, broadcast to relays
- Comments (kind 1111) displayed below articles

**Routes:**
- `/article/d/{slug}` — Article by slug
- `/article/{naddr}` — Article by Nostr address
- `/p/{npub}/d/{slug}` — Article by author npub and slug
- `/{vanity}/d/{slug}` — Article by vanity profile name and slug
- `/e/{identifier}` — Generic event lookup (naddr, nevent, note)

### Highlights & Annotations

- Select text in an article and click "Highlight" to publish a NIP-84 kind 9802 event
- Highlights are signed via the user's Nostr signer and published to relays
- All highlights on an article are displayed inline
- Highlights page (`/highlights`) shows recent highlights across all articles

### Follow System

- Follow/unfollow authors via Nostr follow lists (kind 3)
- View articles from followed authors in the home feed Articles tab
- Follow list synced with user's Nostr contact list on login

### Reading Lists

- Save articles to personal reading lists
- Reading list workflow managed via Symfony Workflow state machine
- Lists are Nostr events synced via relays
- Reading list dropdown on article pages for quick save

### Bookmarks

- Bookmark/unbookmark articles directly from the article page
- Uses Nostr kind 10003 (replaceable bookmarks list)
- Bookmarks page accessible from user profile

### Mute Lists

- User mute lists (kind 10000, NIP-51) synced on login
- Muted authors excluded from all feeds (home, discover, latest)
- Admin-level muting via `ROLE_MUTED`

### Curation Sets

- **Article Curation** (kind 30004) — Boards referencing articles or events by coordinate/ID
- **Picture Curation** (kind 30006) — Instagram-style grid with lightbox navigation
- **Video Curation** (kind 30005) — YouTube-style playlist with sidebar
- Missing referenced media fetched asynchronously with progress UI

### Magazines

Browse magazines (kind 30040, NKBIP-01) on the newsstand:

- Magazine index with category-based article organization
- Bookshelf page for books (kind 30040 referencing kind 30041 content sections)
- Magazine manifest JSON for machine-readable data

**Routes:**
- `/newsstand` — Browse magazines
- `/bookshelf` — Browse books

### Forum / Topics

- Tag-based article browsing at `/forum` and `/forum/tag/{tag}`
- Topic pages generated from interest sets

### Follow Packs

- Browse follow packs (kind 39089) at `/follow-pack/{pubkey}/{dtag}`
- Magazine-style page showing latest articles from pack members
- Add authors to follow packs from their profile pages
- Featured Articles page (`/featured-articles`) for users with Featured Writer role

## Editor Features

### Rich Text Editor

- Live preview mode
- Markdown support with WYSIWYG formatting toolbar (Quill)
- Image upload and embedding (Blossom / NIP-96 media providers)
- Code block support with syntax highlighting
- Table editor
- Mentions: search users and insert `nostr:npub1…` references
- Embeds: insert profile, article, and raw Nostr identifier embeds
- Media library sidebar for inserting from media manager
- Auto-generates `p`, `e`, and `a` tags from `nostr:` references at publish time (NIP-27)

**Routes:**
- `/article-editor/create` — Create new article
- `/article-editor/edit/{slug}` — Edit existing article
- `/article-editor/edit/{slug}/draft` — Edit an article draft

### Draft Management

- Automatic draft saving
- Drafts stored with `PREVIEW` status (kind 30024)
- Only visible to the author
- Publishing converts to signed kind 30023 event

### Publishing Workflow

1. Complete article in editor
2. Add metadata (title, summary, tags, cover image, slug)
3. Preview final result
4. Click "Publish"
5. Sign with Nostr key (NIP-07 or NIP-46)
6. Broadcast to configured relays (user's write relays + local)
7. Article appears in reader

### Translation Helper

Import a Nostr article by naddr or coordinate, edit side-by-side with the original, and publish as a new kind 30023 event with an `a`-tag reference to the original, NIP-32 language labels, and zap-split crediting the original author.

**Route:** `/translation-helper`

## Media Manager

### Media Discovery

Tabbed interface at `/multimedia`:

- **Latest** — Recent media events (kinds 20, 21, 22)
- **Follows** — Media from followed authors (kind 10020 media follows, or kind 3 fallback)
- **Interests** — Media matching interest hashtags
- **Collections** — Picture and video curation sets from the database

**Media Event Types:**
- Kind 20 — Image (NIP-68)
- Kind 21 — Video (NIP-71)
- Kind 22 — Short-form video (NIP-71)
- Kind 34235 — Addressable video (NIP-71)
- Kind 34236 — Addressable short-form video (NIP-71)

### Media Upload

Upload media via Blossom or NIP-96 media providers. Media metadata published as Nostr events.

## Newsroom Features

### Magazine Creation

Create magazines via the wizard at `/magazine/wizard/new`:

1. Define magazine metadata (title, summary, image)
2. Create category structure
3. Add articles to categories (search by title, author, tag)
4. Optionally choose a subdomain (Unfold hosting)
5. Publish as kind 30040 event (NKBIP-01)

### Magazine Journey

A 6-step onboarding wizard (`/blog/start`) for new creators: sync articles from relays, magazine setup, category organization, subdomain selection, and launch.

### Reading Lists

Create and manage reading lists from `/reading-list`. Reading lists can be published as magazine indices.

### Unfold — Subdomain Hosting

Magazines can be hosted at custom subdomains via the Unfold system:

- Custom theme support
- Independent web presence rooted in Nostr content
- Content resolved via local graph layer (fast, no relay round-trips)
- Cache warming via cron for instant page loads

### Content Management

- Add/remove articles from magazines
- Reorder articles within categories
- Update magazine metadata
- Admin can hide/unhide magazines from public view

## Search Features

### Full-Text Search

**PostgreSQL Full-Text Search** (default):
- Built into the database, no additional infrastructure
- Supports advanced filters: date range, author, tags, content type, sort order

**Elasticsearch** (optional):
- Advanced relevance ranking, faceted search, aggregations, typo tolerance
- Enable with `ELASTICSEARCH_ENABLED=true`

### User Search

- Search by name or NIP-05 identifier
- NIP-05 lookup resolves identifiers to hex pubkeys

### Nostr Address Handling

Pasting a Nostr address (`naddr1…`, `nevent1…`) into search decodes it, queries relay hints, and redirects to the event page.

### Active Indexing Service

Optional premium service for priority indexing:
- Immediate indexing vs. eventual
- Paid via Lightning Network

**Route:** `/subscription/active-indexing`

### Updates Pro

Optional subscription for extended Nostr update subscriptions (follows and interest sets):
- Free tier: up to 5 active subscriptions (npub or publication)
- Updates Pro: unlimited subscriptions and NIP-51 set subscriptions
- Paid via Lightning Network (BOLT11 / zap receipt)

**Route:** `/subscription/updates-pro`

## Identity & Authentication

### NIP-05 Verification

- Custom vanity names (e.g. `alice@newsroom.pub`)
- Verification badge on profiles
- Admin-managed mappings

### Authentication Methods

**NIP-07 Browser Extension:**
- Alby, nos2x, or similar
- Direct key access, one-click signing

**NIP-46 Remote Signer:**
- Nsec Bunker or similar
- Remote key storage, signing via relay

### User Settings

Settings page (`/settings`) with tabs:

- **Profile** — Edit and sign-and-publish kind 0 metadata
- **Events** — Dashboard of all user Nostr events (metadata, follows, relay list, bookmarks, interests, mute list, follow packs, etc.)
- **Relays** — NIP-65 relay list editor (add/remove relays, set read/write markers)
- **Subscriptions** — Vanity name, active indexing, subdomain status

### Follow Pack Setup

Create and manage follow packs (kind 39089) from `/settings/follow-pack`. Search users, add members, sign and publish.

## Admin Features

### Dashboard

Central admin interface at `/admin`:
- System overview and quick links
- Tools grid with links to all admin pages

### Admin Pages

| Route | Purpose |
|-------|---------|
| `/admin` | Dashboard |
| `/admin/relay` | Relay pool health, gateway status, mute/unmute relays |
| `/admin/relay/gateway` | Gateway connections, health scores, Redis stream status |
| `/admin/rss` | RSS feed import (fetch, preview, batch-sign, publish) |
| `/admin/feedback` | Feedback messages (kind 24) |
| `/admin/follow-packs` | Assign follow packs for home feed tabs (Podcasts, News Bots) |
| `/admin/magazines` | Magazine management, hide/unhide |
| `/admin/mercure` | Mercure hub diagnostics, connectivity test, topic registry |
| `/admin/role` | Role management (assign/remove roles, view all role holders) |
| `/admin/unfold` | Unfold site management |
| `/admin/vanity-names` | Vanity name administration |
| `/admin/analytics` | Visitor analytics, referrer traffic, subdomain analytics |

### User Management

```bash
# Make user admin
docker compose exec php bin/console user:elevate <npub> ROLE_ADMIN

# Assign RSS manager role
docker compose exec php bin/console user:elevate <npub> ROLE_RSS
```

Roles can also be managed from `/admin/role`.

### RSS Import

Admins and users with `ROLE_RSS` can import articles from RSS/Atom feeds:
1. Fetch any feed URL
2. Preview articles with duplicate detection
3. Select articles to import
4. Batch-sign as kind 30023 events
5. Publish to relays

## Integration Features

### Nostr Protocol Support

Supported NIPs:
- NIP-01 — Basic protocol (with CLOSED/NOTICE handling)
- NIP-05 — DNS-based verification
- NIP-07 — Browser extension signing
- NIP-09 — Event deletion request
- NIP-18 — Reposts (generic repost kind 16)
- NIP-19 — Bech32 encoding (npub, naddr, nevent, nprofile)
- NIP-23 — Long-form content
- NIP-27 — Nostr mentions in content
- NIP-32 — Labeling / content classification
- NIP-42 — Relay authentication
- NIP-46 — Remote signing (Bunker)
- NIP-51 — Lists (bookmarks, mutes, interests, curation sets)
- NIP-56 — Content reporting
- NIP-57 — Zaps (Lightning payments)
- NIP-65 — Relay list metadata
- NIP-66 — Relay monitoring and discovery
- NIP-68 — Picture events
- NIP-71 — Video events
- NIP-84 — Highlights
- NIP-94 — File metadata
- NIP-96 — File storage
- NIP-98 — HTTP authentication
- NIP-B7 — Blossom server list
- NKBIP-01 — Publication indices (magazines)

### RSS Feeds

- Magazine RSS feeds
- Author RSS feeds

### JSON-LD Structured Data

SEO-friendly structured data on article and profile pages (Schema.org Article, Person).

### Lightning Payments

- Zap support (NIP-57)
- LNURL resolution
- Lightning address on profiles (LUD16)
- Zap splits on magazines

## Related Documentation

- [Architecture Overview](ARCHITECTURE.md) — System architecture
- [Developer Guide](DEVELOPER-GUIDE.md) — Contributing
- [Setup Guide](../docs/SETUP.md) — Installation
