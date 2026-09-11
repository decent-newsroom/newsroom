# UnfoldBundle Extraction Readiness Inventory

Status: inventory complete. This document defines the package boundary before
any internal Composer package, namespace, or Git history move.

## Package-owned dependencies

The following implementation belongs in the future Unfold package:

| Surface | Current location | Notes |
| --- | --- | --- |
| Bundle and DI extension | `src/UnfoldBundle/UnfoldBundle.php`, `DependencyInjection/` | Symfony bundle entry point, configuration tree, and bundle service loader. The current bundle path implementation must become package-relative. |
| Publication configuration | `Config/` | `AppData`, `SiteConfig`, and coordinate/NIP-19 parsing are Unfold domain concerns. |
| Publication content mapping | `Content/` | `CategoryData`, `PostData`, and route/content traversal behavior are package-owned. |
| HTTP rendering | `Controller/`, `Http/`, `Theme/`, `EventListener/` | Subdomain detection, page matching, rendered site responses, and theme asset delivery belong to the package. |
| Cache orchestration | `Cache/` | Stale-while-revalidate semantics and publication cache warming belong to the package, but their storage and event-ingestion implementations must be injected. |
| Console entry points | `Command/` | `unfold:cache:warm` is a package command; `unfold:test:fetch` should either become a documented diagnostic command or be test-only. |
| Resources | `Resources/config/`, `Resources/themes/`, `Resources/views/`, `Specs/` | Route and service definitions, Handlebars theme files, theme JS/CSS, Twig demo view, and the bundle-scoped specs must travel with the package. `Specs/README.md:17` explicitly identifies the specs as bundle-scoped. |
| Tests | `tests/Unit/UnfoldBundle/`, `tests/UnfoldBundle/` | Package tests should move into the package after their dependencies are replaced with contracts or test doubles. |

The package will require Symfony FrameworkBundle, Config, DependencyInjection,
HttpFoundation, HttpKernel, Routing, Console, Cache contracts, PSR cache/log,
Doctrine event-listener support where retained, `nostriphant/nip-19`, and
`zordius/lightncandy`. Confirm exact version constraints from the target
Symfony support policy when creating the package manifest; do not copy all
host dependencies.

## Host integration points

No class inside `src/UnfoldBundle` may retain an `App\` dependency after
extraction. Replace these direct imports with bundle contracts and newsroom
adapters:

| Host dependency | Current consumers | Required package seam |
| --- | --- | --- |
| `UnfoldSite` and `UnfoldSiteRepository` | `Http/HostResolver.php:5-16`, `EventListener/UnfoldRequestListener.php:44-48`, `EventListener/UnfoldSiteListener.php:13-20`, `Cache/SiteConfigCacheWarmer.php:18-25`, `Command/WarmCacheCommand.php:20-23` | Define a publication-site value object and site registry interface for subdomain lookup, enumeration, and cache invalidation triggers. Keep the Doctrine entity/repository and lifecycle wiring in the host adapter until the package owns a portable persistence model. |
| Event persistence and Nostr event kinds | `Config/SiteConfigLoader.php:5-37`, `Theme/ContextBuilder.php:5-25` | Provide event lookup/read interfaces returning bundle-defined event data; the host adapts `EventRepository`, `Event`, and `KindsEnum`. |
| Relay client | `Config/SiteConfigLoader.php:7, 32-37`, `Content/ContentProvider.php:5-30`, `Cache/SiteConfigCacheWarmer.php:8, 18-25` | Define a read-only Nostr event gateway for coordinate reads/batches. The warming path additionally needs an explicit optional ingestion port. |
| Graph projection/query | `Content/ContentProvider.php:5, 25-30`, `Cache/SiteConfigCacheWarmer.php:6-7, 18-25` | Define a publication-tree query port and a separate refresh/ingestion port. Do not expose newsroom graph tables through the package API. |
| Profile metadata cache | `Theme/ContextBuilder.php:5-25`, `Controller/ZapApiController.php:5-30` | Define profile metadata lookup returning a package DTO or move this presentation enrichment behind a host-provided context decorator. |
| Markdown conversion | `Theme/ContextBuilder.php:10, 20-25` | Use a bundle-owned conversion contract. The newsroom CommonMark service becomes its adapter. |
| Lightning zap services | `Controller/ZapApiController.php:5-30` | Define a zap-invoice service interface. The current `LNURLResolver`, signer, QR generator, and key converter are newsroom-specific adapters. Replace the hard-coded relay at `ZapApiController.php:23`. |
| Administration and subscription operations | `src/Controller/Administration/UnfoldSiteController.php:30-40`, `src/Service/PublicationSubdomainService.php` | These remain host-owned for now. Phase 3b/7 must move creator administration only after `PublicationContext` is established; platform billing and moderation remain in the host as specified by `Specs/00-refactor-plan.md:108-117`. |
| Main-site discovery/preview | `src/Twig/Components/Organisms/FeaturedUnfoldSites.php`, `MagazinePreview.php`, `src/Service/Admin/AdminDashboardService.php` | Host-owned discovery and operator dashboards should use the site registry adapter, not reach into package persistence. |

## Runtime configuration

| Input | Current source | Extraction requirement |
| --- | --- | --- |
| Base domain | `config/services.yaml:76-79`; injected into `UnfoldRequestListener.php:44-48` | Retain as bundle configuration, with a host-provided value. |
| `UNFOLD_HOST` | `config/services.yaml:76-79` | No bundle consumer was found. Determine whether it belongs to host Caddy/proxy configuration or remove it before package extraction. |
| Themes directory | `DependencyInjection/Configuration.php:19-25` | Replace the host-relative default `%kernel.project_dir%/src/UnfoldBundle/Resources/themes` with the bundle's package path, while allowing a host override directory. |
| Cache pool | `DependencyInjection/Configuration.php:27-30` | Retain as a configurable service ID; the host supplies the implementation. |
| Theme path consumers | `Theme/HandlebarsRenderer.php`, `Controller/ThemeAssetController.php:19-24`, `src/Controller/Administration/UnfoldSiteController.php` | Inject the resolved theme path/configuration rather than rebuilding a `src/UnfoldBundle` path. |
| Route loading | `config/routes.yaml:7-11`, `config/routes/unfold.yaml:1-7` | The package must expose route resources; host import configuration becomes package installation wiring. Preserve ordering before the `/{path}` catch-all in `Resources/config/routes.yaml:37-45`. |
| Service loading | `config/services.yaml:108-117` and `Resources/config/services.yaml:1-13` | Remove the host's broad `App\` discovery for package classes once the bundle extension owns service registration. |
| Twig namespace | `config/packages/twig.yaml:1-15` | Move resource discovery into standard bundle Twig paths or retain an explicit package resource path; avoid a host project-relative path. |
| Scheduled warming | `docker/cron/crontab` and `docker/cron/unfold_cache_warm.sh` | The command belongs to the package; schedule, container image, and log destination remain host operational configuration. |

## Runtime state and storage

| State | Current behavior | Required boundary |
| --- | --- | --- |
| Site registry | Doctrine `unfold_site` table defined by `src/Entity/UnfoldSite.php:14-38` and migrations `Version20260109120000.php`, `Version20260213140540.php` | Keep storage host-controlled until a portable package persistence abstraction is designed. A standalone host can provide its own Doctrine mapping/migration or another registry implementation. |
| Event and publication graph data | Read through host repositories/services; warm path also projects relay events (`Cache/SiteConfigCacheWarmer.php:117-195`) | Host-controlled persistence exposed through interfaces; no direct Doctrine entity/repository dependency in package code. |
| SWR and rendered HTML caches | PSR cache through `StaleWhileRevalidateCache.php:25-28` and `ContextBuilder.php:20-25` | Safe to retain as injected PSR cache pools. Cache keys should be namespaced by the package. |
| Compiled Handlebars templates | `Theme/HandlebarsRenderer.php` writes generated PHP files | Continue using `%kernel.cache_dir%` or an injected writable cache directory. Never write under the Composer-installed theme directory. |
| Theme assets | Read only via `ThemeAssetController.php:39-69` | Package-owned, read-only files; route response should use the configured package/override theme locator. |

## Resolved boundary decisions

| Decision | Resolution |
| --- | --- |
| Persistence ownership | The package remains persistence-agnostic. It defines a site registry interface and value objects; each host provides its own storage adapter. Doctrine mappings, migrations, and the existing `UnfoldSite` entity remain newsroom-owned. |
| Nostr boundary | The package defines an immutable Nostr event DTO plus a relay-read gateway interface. Hosts adapt their relay clients and database event records to this package model. |
| Graph lookup | Publication-tree lookup is an optional host capability. The package remains functional through the relay-read gateway when no graph implementation is registered. |
| Zap invoices | NIP-57 zap invoice generation remains a publication-facing, optional package capability behind a `ZapInvoiceServiceInterface`. The newsroom provides the initial adapter. |
| Creator administration | The package will own publication-owner administration after `PublicationContext` is implemented. DN platform moderation and billing remain host-only. |

The current loader mixes direct relay reads with an `EventRepository` fast path
(`Config/SiteConfigLoader.php:141-189`), while `ContentProvider` already
supports the selected optional-graph model (`ContentProvider.php:25-30, 60-80`).
`UnfoldSiteController` must not move mechanically because it mixes platform
`ROLE_ADMIN` operations, host event persistence, and browser signing.

## Next implementation increment

The initial contracts now live in `src/UnfoldBundle/Contract/`:
`SiteRegistryInterface`, `PublicationSite`, `NostrEvent`,
`EventReadGatewayInterface`, `PublicationTreeLookupInterface`,
`ZapInvoiceRequest`, `ZapInvoice`, and `ZapInvoiceServiceInterface`.
The newsroom host adapters now live in `src/Unfold/`: `SiteRegistryAdapter`
maps `UnfoldSite` records to `PublicationSite`, `EventReadGatewayAdapter`
provides database-first coordinate reads with relay fallback, and
`PublicationTreeLookupAdapter` maps ordered graph rows to `NostrEvent` DTOs.
Symfony aliases wire these adapters to the three package interfaces.

`SiteConfigLoader` and `ContentProvider` now consume only the event/tree
contracts. Host resolution, request marking, demo rendering, and cache-warm
command site enumeration also use `PublicationSite`; the cache warmer retains
an entity overload because its refresh path intentionally performs host-owned
event projection and graph ingestion. `UnfoldSiteListener` likewise remains
entity-bound for Doctrine lifecycle events. Zap invoice consumers,
administration, comment/zap presentation enrichment in `Theme/ContextBuilder`,
and the warming ingestion dependencies are intentionally deferred until their
host lifecycle seams are defined.

After that migration, create the internal `packages/unfold-bundle/` Composer
boundary and move package-owned source and resources with `git mv`; retain
`App\UnfoldBundle` temporarily if necessary, but remove host-level autoloading
and project-relative resource paths when the host begins consuming the package
through a Composer path repository.

Implement `PublicationContext` before moving creator administration. The later
extraction must satisfy the Phase 7 direction to introduce bundle-owned
interfaces plus host adapters before following the package extraction process
(`Specs/00-refactor-plan.md:172-179`).
