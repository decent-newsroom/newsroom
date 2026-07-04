# Documentation Summary

Summary of public-facing documentation for Decent Newsroom.

## Documentation Files

| File | Purpose |
|------|---------|
| [INDEX.md](INDEX.md) | Documentation index (start here) |
| [GETTING-STARTED.md](GETTING-STARTED.md) | User onboarding for readers, writers, publishers |
| [FEATURES.md](FEATURES.md) | Feature reference |
| [FAQ.md](FAQ.md) | Frequently asked questions |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Technical architecture |
| [DEVELOPER-GUIDE.md](DEVELOPER-GUIDE.md) | Developer handbook |
| [QUICK-REFERENCE.md](QUICK-REFERENCE.md) | Command cheat sheet |
| [OVERVIEW.md](OVERVIEW.md) | Documentation overview and reading paths |

## Documentation Structure

```
docs-public/            Public-facing documentation
docs/                   Setup, deployment, operations (SETUP.md, production.md, etc.)
documentation/          Internal feature docs (one file per feature, by area)
  NIP/                  Nostr Implementation Possibilities reference
  NKBIP/                Nostr Key Binding Implementation Possibilities
```

## Coverage

### Audiences

- **Readers** — Discovering content, following authors, highlighting, bookmarks, reading lists
- **Writers** — Creating and publishing articles, formatting, media upload, drafts
- **Publishers** — Creating magazines, curating content, Unfold subdomain hosting
- **Developers** — Environment setup, code structure, conventions, testing, contributing
- **System admins** — Installation, deployment, configuration, maintenance

### Topics

- Platform introduction and Nostr identity setup
- Article reading, writing, and publishing
- Magazine creation and management
- Media management and discovery (Blossom / NIP-96)
- Highlights and comments
- Search (Elasticsearch and database)
- System architecture and service topology
- Docker services, workers, and cron
- Relay infrastructure (two-tier: local strfry + user NIP-65)
- Graph layer (current records, parsed references)
- Frontend architecture (Stimulus, Turbo, Twig Live Components)
- Development workflow and commands

## How to Use

- **New users:** Start with [GETTING-STARTED.md](GETTING-STARTED.md)
- **Developers:** Start with [DEVELOPER-GUIDE.md](DEVELOPER-GUIDE.md)
- **System admins:** Start with [Setup Guide](../docs/SETUP.md)
- **Everyone:** Use [INDEX.md](INDEX.md) to find what you need

## Maintenance

When making changes to the project:

1. Update relevant documentation files
2. Verify code examples and commands still work
3. Follow existing style conventions (clear headings, code blocks with language tags, cross-references)
4. Add new features to the changelog

---

**Main entry point:** [INDEX.md](INDEX.md)
