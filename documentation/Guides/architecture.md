# Architecture

Decent Newsroom is a Symfony application that projects Nostr events into a local reading and publishing experience. This guide describes the main boundaries; feature documents and configuration files carry the detailed behavior.

## Runtime

| Component | Responsibility | Source of configuration |
|---|---|---|
| Symfony and FrankenPHP | PHP application, Caddy web server, Mercure hub | [composer.json](../../composer.json), [frankenphp](../../frankenphp/) |
| PostgreSQL | Entities, article search, publication graph | [Compose](../../compose.yaml), [Doctrine configuration](../../config/packages/doctrine.yaml) |
| Redis | Cache, sessions, view store, Messenger streams | [package configuration](../../config/packages/), [development Compose override](../../compose.override.yaml) |
| strfry | Local relay and router | [relay configuration](../../docker/strfry/) |
| Elasticsearch | Optional search backend | [search documentation](../Elasticsearch/elasticsearch.md) |

PHP and Symfony requirements live in `composer.json`. Set `POSTGRES_VERSION` consistently for the database image and connection's `serverVersion`; inspect the Compose configuration rather than relying on a duplicated version table.

The development Compose override supplies local Redis and source mounts. Production configuration is separate. See [setup](../../docs/SETUP.md) and [production deployment](../../docs/production.md).

## Application and package boundaries

The application owns HTTP flows, domain entities, projections, and integration adapters under `src/`. Reusable Nostr kernel, client, identity, signing, relay gateway, expression, and bookshelf functionality is installed as Composer packages. Their bundle registration is in [config/bundles.php](../../config/bundles.php); their versions and sources are in [composer.lock](../../composer.lock).

Unfold is a Composer path dependency developed in [packages/unfold-bundle](../../packages/unfold-bundle/). It is no longer an application bundle under `src/UnfoldBundle`. Application-facing relay services under `src/Service/Nostr/` connect the packaged infrastructure to Newsroom's user context and persistence.

## Event and read flow

An editor publish request verifies a signed Nostr event, saves the article, updates its local projections, and attempts relay publication. Remote ingestion also enters through subscription workers and asynchronous handlers. These paths apply event-kind projection, revision resolution, and deletion rules before content becomes available to readers.

PostgreSQL stores articles, generic events, profiles, and application state. The graph layer in [src/Service/Graph](../../src/Service/Graph/) maintains `current_record` and `parsed_reference` for publication traversal. Redis views provide fast article/profile reads; controllers can use database fallbacks and dispatch missing-content fetches.

See [Nostr publishing](nostr-cms.md), [Redis views](../Redis/redis-views.md), [deletion handling](../Nostr/nip-09-deletion.md), and [Unfold](../Unfold/unfold.md).

## Background work

| Service | Responsibility |
|---|---|
| `worker` | Messenger consumers for content, low-priority jobs, expressions, and relay feeds |
| `worker-relay` | Persistent local relay subscriptions for articles, media, publications, and user context |
| `worker-profiles` | Profile refresh daemon and profile message consumer |
| `cron` | Scheduled maintenance, fetching, and cache refresh |
| `relay-gateway` | Optional persistent external relay connection pool (`gateway` profile) |
| `strfry-essayist`, `essayist-gateway` | Optional Essayist relay and public ingress (`essayist` profile) |
| `mcp` | Optional read-only MCP service backed by the internal article API (`mcp` profile) |

[Messenger configuration](../../config/packages/messenger.yaml) defines five transport lanes: `async`, `async_low_priority`, `async_expressions`, `async_relay_feeds`, and `async_profiles`. [Worker documentation](../Processes/workers.md) explains their managers; [docker/cron/crontab](../../docker/cron/crontab) is the schedule source of truth.

## Relay selection

Anonymous reads use the instance's configured relay infrastructure. Signed-in user context can augment relay selection using NIP-65 relay lists and follows. User-specific choices should stay scoped to that user rather than change global registry defaults.

The optional relay gateway handles persistent connections and relay authentication. Publishing and fetching may use different relay paths; consult the [relay gateway guide](../Nostr/relay-gateway-service.md) for the current integration.

## Frontend

Twig renders pages and Atomic Design components in `templates/components/`. Symfony UX Live Components provide server-backed interactions; Stimulus controllers and Turbo provide browser behavior.

AssetMapper serves JavaScript and CSS from `assets/`, with packages declared in `importmap.php`. TypeScript under `assets/typescript/` is compiled through the TypeScript bundle. Styles and scripts belong in assets rather than inline templates. Translated UI strings live in `translations/messages.*.yaml`.

See [development](development.md) for the working conventions and [the documentation index](../INDEX.md) for individual features.
