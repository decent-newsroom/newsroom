# Unfold Setup And Local Settings

An Unfold is permanently identified by exactly one root magazine coordinate.
Operator setup and subscription activation share a local setup service. Creating
or editing a site does not require a signer, an AppData event, or relay publication.
This file retains its former name so existing documentation links keep working.

## Overview

Administrators create hosted sites from `/admin/unfold/new` using the subdomain,
a `30040:<64-hex-pubkey>:<identifier>` root coordinate, and a supported theme.
The form uses an ordinary CSRF-protected submission in every environment.

An existing Unfold keeps its full root coordinate when edited. Local settings
store the selected theme by that coordinate, separately from the subdomain
mapping and subscription billing. Existing sites without settings use the default
theme.

## Architecture

### Flow

1. The administration controller accepts and validates a CSRF-protected request.
2. The shared setup service validates the root identity, hosting mapping, and
   selected theme, then persists the mapping and coordinate-keyed settings.
3. Subscription activation uses the same service and preserves settings when it
   does not supply a new theme.
4. Runtime site configuration resolves publication metadata/content from the root
   index and applies the stored local theme. Settings changes invalidate the
   affected site configuration cache.

The root index and its descendants remain authoritative for title, description,
navigation, and content. Saving local settings does not modify those events.
Legacy AppData loading remains compatibility-only and is not used by setup.

### Key files

| File | Role |
|---|---|
| `src/Controller/Administration/UnfoldSiteController.php` | Operator creation and editing |
| `src/Unfold/UnfoldSetupService.php` | Shared local setup |
| `src/Service/PublicationSubdomainService.php` | Subscription activation caller |
| `templates/admin/unfold/new.html.twig` | Standard setup form |
| `packages/unfold-bundle/src/Config/SiteConfigLoader.php` | Runtime root-index and settings resolution |

## Configuration And Migration

Apply the new Doctrine migration for local publication settings before running
the updated application:

```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

No new environment variable, event coordinate, or signing credential is required.
Existing subdomain mappings need no AppData backfill and retain their URLs.

## Limitations And Planned Work

- Theme is the current persisted local setting. Audience/payment selections and
  access-service configuration will be introduced with their actual workflows.
- Owner administration on both mounts, subdomain-first reservations, scoped access,
  and a custom portable event are separate future slices.
- The future portable definition will describe the relationships established by
  the working scoped-access model. Its kind/schema are intentionally deferred;
  setup and local saves will remain independent of its publication.

See the package [master plan](../../packages/unfold-bundle/Specs/00-refactor-plan.md)
for the staged design.
