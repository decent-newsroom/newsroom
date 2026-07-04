# Getting Started

Welcome to Decent Newsroom. This guide covers everything you need to start using the platform.

## For Readers

### Discovering Content

1. **Visit the homepage** — See the latest articles and featured content
2. **Browse the newsstand** at `/newsstand` for curated magazines
3. **Search for content** using the search bar (supports articles, authors, tags)
4. **Explore topics** at `/forum` for tag-based article browsing
5. **Check highlights** at `/highlights` to see what others are annotating

### Reading Articles

Click any article title to open it. Articles feature:
- Clean, typography-optimized reading experience
- Support for images, code blocks, tables, and math (KaTeX)
- Comments section at the bottom
- Related content suggestions

### Creating an Account

To unlock interactive features, you need a Nostr identity.

**Option 1: Browser Extension (Recommended)**

1. Install a Nostr browser extension:
   - [Alby](https://getalby.com/) (includes Lightning wallet)
   - [nos2x](https://github.com/fiatjaf/nos2x) (minimal)

2. Create or import your Nostr keys in the extension
3. Visit the login page and click "Connect with Extension"
4. Approve the connection request

**Option 2: Remote Signer**

1. Set up a remote signer like [Nsec Bunker](https://nsecbunker.com/)
2. Get your bunker connection string
3. Choose "Remote Signer" on the login page
4. Enter your connection string and approve signing requests

### Interacting with Content

Once logged in:

**Highlight text:**
1. Select text in an article
2. Click the "Highlight" button that appears
3. Your highlight is signed and published as a kind 9802 event

**Bookmark articles:**
1. Click the actions dropdown on any article
2. Select "Bookmark"
3. Access bookmarks from your profile

**Follow authors:**
1. Visit an author's profile
2. Click "Follow"
3. See their articles in your home feed Articles tab

**Save to reading lists:**
1. Use the reading list dropdown on any article
2. Add to an existing list or create a new one

**Comment:**
1. Scroll to the comment section below an article
2. Write your comment
3. Sign and publish as a kind 1111 event

## For Writers

### Creating Your First Article

**Step 1: Log in** with your Nostr identity.

**Step 2: Open the Editor** — Click "Write" in the navigation, or visit `/article-editor/create`.

**Step 3: Write** — The editor supports:
- Markdown with a Quill WYSIWYG toolbar
- Live preview mode
- Image upload via Blossom / NIP-96
- Code blocks with syntax highlighting
- Tables
- Mentions (`nostr:npub1…`) and embeds (`nostr:naddr1…`)
- Media library sidebar

**Step 4: Add Metadata**
- Title and summary (appears in previews and search)
- Cover image
- Tags for discoverability
- Custom slug (URL identifier)

**Step 5: Publish**
- Click "Publish"
- Sign the kind 30023 event with your Nostr key
- The article is broadcast to your write relays and the local relay

### Article Formatting

The editor supports Markdown:

```markdown
# Heading 1
## Heading 2

**bold** and *italic*

[Link text](https://example.com)

![Image alt text](https://example.com/image.jpg)

- Unordered list
1. Ordered list

> Blockquote

\(x^2 + y^2 = z^2\)   (KaTeX math)
```

Code blocks:
````markdown
```javascript
console.log("Hello");
```
````

### Managing Content

**Drafts:** Articles auto-save as drafts (kind 30024). Access from your profile or editor.

**Editing:** Click "Edit" on a published article. Publish again to create a new version.

**Translation Helper:** At `/translation-helper`, import an article by naddr, edit side-by-side with the original, and publish a translation with proper attribution and zap-split.

### Building Your Audience

- Complete your profile at `/settings` (Profile tab)
- Set up NIP-05 verification for a verified badge
- Add a Lightning address (LUD16) to receive tips
- Create a follow pack at `/settings/follow-pack` to recommend other writers

## For Publishers

### Creating a Magazine

Magazines are curated collections of articles organized into categories.

**Step 1: Plan** — Choose a theme, name, and category structure.

**Step 2: Create** — Visit `/magazine/wizard/new` and start a new magazine:
1. Enter title, slug, summary, and cover image
2. Create categories (e.g., News, Opinion, Features)
3. Search for articles by title, author, or tag
4. Add articles to categories
5. Optionally choose a subdomain (Unfold hosting)
6. Publish as a kind 30040 event (NKBIP-01)

### Magazine Journey

New creators can use the guided onboarding wizard at `/blog/start`:
1. Sync your articles from relays
2. Set up magazine metadata
3. Organize articles into categories
4. Choose a free subdomain
5. Launch

### Unfold — Subdomain Hosting

Magazines can be hosted at custom subdomains with their own theme. Content is resolved from the local database for fast page loads.

### Managing Content

- Add/remove articles from magazine categories
- Reorder articles within categories
- Update magazine metadata and republish
- View authored articles and drafts at `/my-content`; manage reading lists at `/reading-list`

## Understanding Nostr

### Key Concepts

- **Keys** — Your identity is a cryptographic key pair (public npub / private nsec)
- **Events** — All actions are signed messages (articles, follows, highlights, etc.)
- **Relays** — Servers that store and forward events
- **Clients** — Applications that interact with relays (Decent Newsroom is a client)
- **Kinds** — Event type numbers (30023 = articles, 30040 = magazines, 0 = profiles, etc.)

### Why Nostr Matters

- **Censorship resistance** — Content distributed across multiple relays
- **Portability** — Your content works across all Nostr clients
- **Ownership** — You own your identity and content via cryptographic keys
- **Open protocol** — Anyone can build clients and tools

### Nostr and Newsroom

Articles are kind 30023 events, magazines are kind 30040 events (NKBIP-01), profiles are kind 0 metadata, highlights are kind 9802, and comments are kind 1111. All are standard Nostr events that work with other clients.

The platform caches events locally in PostgreSQL and Redis for performance, while keeping everything synced with the broader Nostr network via relays.

## Common Tasks

### Changing Your Display Name

1. Go to `/settings` → Profile tab
2. Update your display name
3. Sign and publish the updated kind 0 metadata
4. Changes propagate across the platform and other Nostr clients

### Managing Your Relay List

1. Go to `/settings` → Relays tab
2. Add or remove relays
3. Set read/write markers per relay
4. Sign and publish the updated kind 10002 event

### Setting Up Lightning Payments

1. Get a Lightning address (from Alby, Strike, etc.)
2. Go to `/settings` → Profile tab
3. Add your Lightning address to the LUD16 field
4. Sign and publish — readers can now send you sats

## Troubleshooting

### Can't Log In

- Browser extension installed and unlocked?
- Extension has permissions for this site?
- Try refreshing or a different browser
- Try NIP-46 remote signer

### Articles Not Appearing

- Wait a few minutes for indexing
- Check that the article was published (not just a draft)
- Verify on another Nostr client

### Images Not Loading

- URL must be HTTPS and publicly accessible
- Use direct image URLs (not webpage URLs)
- Try nostr.build as a host

## Getting Help

- [Features Guide](FEATURES.md) — Complete feature reference
- [FAQ](FAQ.md) — Common questions
- [Architecture](ARCHITECTURE.md) — Technical details
- [GitHub Issues](https://github.com/decent-newsroom/newsroom/issues) — Bug reports
