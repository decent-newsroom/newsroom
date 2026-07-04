# Frequently Asked Questions

Common questions about Decent Newsroom, Nostr, and decentralized publishing.

## General Questions

### What is Decent Newsroom?

Decent Newsroom is a decentralized publishing platform built on the Nostr protocol. It enables collaborative journalism and content creation without central control. Think of it as a combination of Medium, Substack, and a traditional newsroom, but fully decentralized.

### Is this free to use?

Yes. The platform is open-source (MIT license) and free to use. You can read, publish, create magazines, and self-host your own instance.

Optional paid features:
- Active Indexing service (priority indexing)
- Vanity names (custom NIP-05 identifiers)
- Publication subdomains (Unfold hosting)

### How is this different from Medium or Substack?

- **No central control** — Content can't be censored by a platform owner
- **You own your identity** — Your Nostr keys work across all compatible apps
- **Portable content** — Articles are Nostr events, not locked to one platform
- **Open source** — Anyone can run their own instance or modify the code

### Can I self-host this?

Yes. The entire platform is open source and designed to be self-hosted via Docker. See the [Setup Guide](../docs/SETUP.md).

## Nostr Questions

### What is Nostr?

Nostr (Notes and Other Stuff Transmitted by Relays) is an open protocol for decentralized social networking. It's based on cryptographic key pairs, relay servers, and signed events.

Learn more: [nostr.com](https://nostr.com)

### Do I need a Nostr account?

You need a Nostr identity (key pair) to publish articles, create magazines, follow authors, highlight content, and comment. You don't need one to read.

### How do I get a Nostr identity?

Install a browser extension:
- [Alby](https://getalby.com/) — Includes Lightning wallet
- [nos2x](https://github.com/fiatjaf/nos2x) — Minimal

Or use a remote signer:
- [Nsec Bunker](https://nsecbunker.com/) — Remote key management

### What are npub and nsec?

- **npub** — Your public key (safe to share, like a username)
- **nsec** — Your private key (never share, like a password)

### Can I use my existing Nostr identity?

Yes. If you have a Nostr identity from another app (Damus, Amethyst, Primal, etc.), just use the same keys.

### What if I lose my keys?

There is no password reset with cryptographic keys. If you lose your nsec, you lose that identity.

**Backup strategies:**
- Store your nsec in a password-protected file or as ncryptsec.
- Use a password manager
- Consider NIP-46 remote signers

### Are my keys safe?

We never see or store your private keys. Signing happens in your browser extension (NIP-07) or remote signer (NIP-46), never on our servers.

## Content Questions

### Who owns the content I publish?

You do. Content published to Nostr is signed with your keys, distributed across relays, and portable across clients.

### Can my content be censored?

Individual relay operators can choose not to store events, but content is distributed across multiple relays. This is censorship-resistant, not censorship-proof.

### Can I edit or delete published articles?

**Editing:** Publish a new version with the same slug. The new event replaces the old one (old versions remain on relays).

**Deleting:** You can hide articles from the platform, but Nostr events are designed to be permanent.

### How do I format articles?

Articles use Markdown. The editor provides a WYSIWYG toolbar (Quill) and supports images, code blocks, tables, and KaTeX math.

### Can I include images?

Yes. Upload via the media manager (Blossom / NIP-96 providers) or paste URLs directly:

```markdown
![Alt text](https://example.com/image.jpg)
```

## Magazine Questions

### What is a magazine?

A magazine is a curated collection of articles organized into categories, published as a Nostr kind 30040 event (NKBIP-01).

### How do I create one?

1. Log in → go to `/magazine/wizard/new`
2. Start a new magazine
3. Add metadata, categories, articles
4. Publish (signs a kind 30040 event)

### Can I add other people's articles?

Yes. Magazines reference articles by Nostr coordinates — you link to them, not copy them. Original authors retain ownership.

### What is Unfold?

Unfold is the subdomain hosting system. Magazines can be served at custom subdomains with their own theme, while content stays rooted in Nostr events.

## Technical Questions

### What technology stack is this?

- **Backend:** Symfony 7.4 (PHP 8.3+)
- **Database:** PostgreSQL 16+ (version configurable via `POSTGRES_VERSION`)
- **Cache/Queue:** Redis
- **Web Server:** FrankenPHP (Caddy + PHP)
- **Relay:** Strfry (Nostr relay)
- **Frontend:** Stimulus, Turbo, AssetMapper (no npm/webpack)
- **Real-time:** Mercure (Server-Sent Events)
- **Search:** PostgreSQL full-text + optional Elasticsearch

See [ARCHITECTURE.md](ARCHITECTURE.md) for details.

### How does search work?

**PostgreSQL Full-Text Search** (default) — Built into the database, supports advanced filters (date range, author, tags, sort order).

**Elasticsearch** (optional) — Advanced relevance ranking, faceted search. Enable with `ELASTICSEARCH_ENABLED=true`.

### Why is my article not showing up?

Possible reasons:
- Indexing worker is processing a backlog
- Event not yet received by local relay
- Article is a draft (kind 30024), not published (kind 30023)

Usually resolves within a few minutes.

### Can I run this without Docker?

Yes, but Docker is strongly recommended. You'd need PHP 8.3+, PostgreSQL, Redis, Strfry, and a web server.

### How do I back up my data?

```bash
# Database backup
docker compose exec database pg_dump -U app app > backup.sql

# Your articles are also Nostr events on relays — they're backed up across the network.
```

## Privacy & Security

### Is my data private?

**Public by default:** Published articles, profiles, follows, and magazines are public Nostr events.

**Private:** Drafts (until published), your private key (nsec), session data.

### How is authentication secured?

- No passwords — cryptographic key-based auth
- Private keys never leave your device
- Redis-backed sessions with secure cookies
- HTTPS enforced in production

## Troubleshooting

### Can't log in

- Check that browser extension is installed and unlocked
- Extension needs permissions for this site
- Try refreshing the page
- Try NIP-46 remote signer as alternative

### Articles not appearing

- Wait a few minutes for indexing
- Check worker logs: `docker compose logs worker-relay`
- Verify the article was published (not just saved as draft)

### Images not loading

- Verify URL is accessible and uses HTTPS
- Check that the image host allows hotlinking
- Try Nostr-friendly hosts (nostr.build)

### Profile not updating

- Clear browser cache (Ctrl+F5)
- Wait ~5 minutes for cache expiry
- Check that profile update was signed and published

## Getting Help

- [Features Guide](FEATURES.md) — Feature documentation
- [Architecture](ARCHITECTURE.md) — Technical overview
- [Developer Guide](DEVELOPER-GUIDE.md) — Development setup
- [Setup Guide](../docs/SETUP.md) — Installation
- [GitHub Issues](https://github.com/decent-newsroom/newsroom/issues) — Bug reports
