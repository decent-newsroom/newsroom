# Skills Index

Reusable step-by-step guides for maintaining and extending this Symfony/Nostr application. Each file is self-contained and includes a checklist.

> All terminal commands must be run **inside the Docker container**:
> `docker compose exec php bin/console <command>`

---

## Available Skills

| Skill | When to use |
|---|---|
| [add-nostr-event-kind.md](add-nostr-event-kind.md) | A NIP introduces a new event `kind` to ingest, persist, or surface |
| [create-twig-live-component.md](create-twig-live-component.md) | Adding a server-rendered interactive UI component (Atom / Molecule / Organism) |
| [create-async-message-handler.md](create-async-message-handler.md) | Deferring work from a request or relay worker via Symfony Messenger |
| [add-doctrine-entity.md](add-doctrine-entity.md) | Adding a new PostgreSQL table with Doctrine ORM + migration |
| [create-stimulus-controller.md](create-stimulus-controller.md) | Adding client-side behaviour via a Stimulus JS controller |
| [add-redis-read-model.md](add-redis-read-model.md) | Fast page rendering via pre-computed Redis views (cron-populated) |
| [add-ingestion-gate.md](add-ingestion-gate.md) | Silently dropping events at ingestion time (ban policy, tombstone-style) |
| [augment-relay-selection.md](augment-relay-selection.md) | Adding per-user or per-feature relay augmentation without polluting the global registry |
| [add-console-command.md](add-console-command.md) | Adding a `bin/console` command (cron job, admin utility, worker loop, backfill) |
| [write-nip-feature-spec.md](write-nip-feature-spec.md) | Writing a Gherkin `.feature` spec for NIP protocol compliance |
| [add-translations.md](add-translations.md) | Adding i18n strings across all 5 locale files |
| [add-feature-documentation.md](add-feature-documentation.md) | Documenting a new feature in `documentation/` |
| [run-rector.md](run-rector.md) | Running and configuring Rector for automated PHP modernisation and code quality |
| [phase-1-navigation-refactor.md](phase-1-navigation-refactor.md) | Implementing the navigation refactor Phase 1 (Reading Nook and Newsroom layouts) |

---

## Quick decision tree

```
New Nostr event kind to support?
  → add-nostr-event-kind

Interactive UI widget?
  → create-twig-live-component (PHP/Twig)
  → create-stimulus-controller (JS behaviour)

Background / async work?
  → create-async-message-handler

New database table?
  → add-doctrine-entity

Fast read / cached page data?
  → add-redis-read-model

Drop events at ingestion?
  → add-ingestion-gate

Extra relays for a feature?
  → augment-relay-selection

CLI tool / cron job?
  → add-console-command

Protocol compliance test?
  → write-nip-feature-spec

User-facing text?
  → add-translations

Documentation for a new feature?
  → add-feature-documentation

Code quality / PHP modernisation?
   → run-rector

Navigation / layout refactoring?
   → phase-1-navigation-refactor
```
 


