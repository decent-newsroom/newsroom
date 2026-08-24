# Skill: Add a Feature Documentation File

Use this skill whenever a new feature, architecture decision, or integration is implemented. Documentation lives in `documentation/` and should be created as part of the same PR/commit as the code.

---

## Where to put the file

```
documentation/
    Admin/          → Admin-facing features, moderation tools
    Audience/       → Reader/subscriber features
    Business/       → Monetisation, subscriptions, Essayist
    Chat/           → NIP-28 chat, subdomain chat
    Cron/           → Scheduled job documentation
    Deployment/     → Infrastructure, Docker, production
    Editor/         → Article editor features
    Elasticsearch/  → Search implementation
    Essayist/       → Essayist membership platform
    Media/          → Blossom, NIP-96, media discovery
    Newsroom/       → Magazine, reading list, publishing flows
    NIP/            → Nostr NIP reference summaries
    NKBIP/          → NKBIP reference summaries
    Nostr/          → Nostr protocol integration details
    Notifications/  → Mercure SSE, update notifications
    Processes/      → Background workers, Messenger
    Reader/         → Article reading experience
    Redis/          → Caching, view store
    RSS/            → RSS ingestion
    Strfry/         → Local relay configuration
    Subscriptions/  → Active indexing, vanity names
    Unfold/         → Unfold hosted magazine bundle
```

One file per feature. Avoid splitting a single feature across multiple files.

---

## Template

File: `documentation/{Area}/your-feature.md`

```markdown
# Your Feature Name

Brief one-paragraph description of what the feature does and why it exists.

## Overview

High-level description. Include:
- What problem it solves
- Who uses it (reader, writer, admin, system)
- Key constraints or decisions made

## Architecture

### Data model

Which entities / tables are involved. Example:
- `YourEntity` — stores X, keyed by pubkey + d-tag
- Redis key `your_list_v1` — cached list for homepage rendering

### Flow

Step-by-step description of how data moves through the system:

1. User action or cron trigger
2. Message dispatched to Messenger transport `async`
3. Handler fetches from relay / DB
4. Projector persists to entity
5. Redis view invalidated / rebuilt

### Key files

| File | Role |
|---|---|
| `src/Entity/YourEntity.php` | Doctrine entity |
| `src/Service/YourService.php` | Core business logic |
| `src/MessageHandler/YourHandler.php` | Async processing |
| `src/Command/YourCommand.php` | CLI / cron entry point |
| `assets/controllers/domain/your_controller.js` | Frontend behaviour |
| `templates/your/index.html.twig` | Main template |

## Configuration

Environment variables or `services.yaml` parameters introduced:

| Parameter | Default | Description |
|---|---|---|
| `YOUR_ENV_VAR` | `false` | Enable/disable the feature |

## Limitations / Known Issues

- List any known edge cases or intentional limitations.
- Link to backlog items if relevant.

## Related NIPs / NKBIPs

- [NIP-XX](../NIP/NIP-XX.md) — description of relevance
```

---

## Rules

- **One file per feature** — don't create multiple files for one feature.
- **Keep docs next to the relevant area folder** — don't put everything in the root `documentation/` directory.
- **Link to related NIPs** — if the feature implements a NIP, reference it.
- **Update `documentation/INDEX.md`** after creating a new file.

---

## Checklist

- [ ] File created in the appropriate `documentation/{Area}/` subfolder
- [ ] File follows the template structure
- [ ] `documentation/INDEX.md` updated with the new entry
- [ ] `CHANGELOG.md` entry added (for the feature itself, not the docs)

