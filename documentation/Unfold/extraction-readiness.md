# UnfoldBundle Extraction Readiness Inventory

Status: internal Composer package extracted. This document records the boundary
and deliberate host integration seams; Git repository extraction is out of scope
for this step.

## Package-owned dependencies

The following implementation belongs in the future Unfold package:

| Surface | Current location | Notes |
| --- | --- | --- |
| Bundle and DI extension | `packages/unfold-bundle/src/UnfoldBundle.php`, `DependencyInjection/` | Symfony bundle entry point, configuration tree, and package-relative service loader. |
| Publication configuration | `Config/` | `AppData`, `SiteConfig`, and coordinate/NIP-19 parsing are Unfold domain concerns. |
| Publication content mapping | `Content/` | `CategoryData`, `PostData`, and route/content traversal behavior are package-owned. |
| HTTP rendering | `Controller/`, `Http/`, `Theme/`, `EventListener/` | Subdomain detection, page matching, rendered site responses, and theme asset delivery belong to the package. |
| Cache orchestration | `Cache/` | Stale-while-revalidate semantics and publication cache warming belong to the package, but their storage and event-ingestion implementations must be injected. |
| Console entry points | `Command/` | `unfold:cache:warm` is a package command; `unfold:test:fetch` should either become a documented diagnostic command or be test-only. |
| Resources | `Resources/config/`, `Resources/themes/`, `Resources/views/`, `Specs/` | Route and service definitions, Handlebars theme files, theme JS/CSS, Twig demo view, and the bundle-scoped specs must travel with the package. `Specs/README.md:17` explicitly identifies the specs as bundle-scoped. |
| Tests | `packages/unfold-bundle/tests/`, `tests/Unfold/Adapter/` | Contract/content tests move with the package; host adapter tests remain in the newsroom. |

The package manifest now declares only the components used directly by package
code: Symfony FrameworkBundle, Config, DependencyInjection, EventDispatcher,
HttpFoundation, HttpKernel, Mime, Routing, Console, Yaml, PSR cache/log,
`nostriphant/nip-19`, and `zordius/lightncandy`. Doctrine and newsroom protocol
services remain host dependencies behind the contracts below.

## Host integration points

No class inside `packages/unfold-bundle/src` may retain an `App\` dependency after
extraction. Replace these direct imports with bundle contracts and newsroom
adapters:

| Host dependency | Current consumers | Required package seam |
| --- | --- | --- |
| `UnfoldSite` and `UnfoldSiteRepository` | `Http/HostResolver.php:5-16`, `EventListener/UnfoldRequestListener.php:44-48`, `EventListener/UnfoldSiteListener.php:13-20`, `Cache/SiteConfigCacheWarmer.php:18-25`, `Command/WarmCacheCommand.php:20-23` | Define a publication-site value object and site registry interface for subdomain lookup, enumeration, and cache invalidation triggers. Keep the Doctrine entity/repository and lifecycle wiring in the host adapter until the package owns a portable persistence model. |
| Event persistence and Nostr event kinds | `Config/SiteConfigLoader.php:5-37`, `Theme/ContextBuilder.php:5-25` | Provide event lookup/read interfaces returning bundle-defined event data; the host adapts `EventRepository`, `Event`, and `KindsEnum`. |
| Relay client | `Config/SiteConfigLoader.php:7, 32-37`, `Content/ContentProvider.php:5-30`, `Cache/SiteConfigCacheWarmer.php:8, 18-25` | Define a read-only Nostr event gateway for coordinate reads/batches. The warming path additionally needs an explicit optional ingestion port. |
| Graph projection/query | `Content/ContentProvider.php:5, 25-30`, `Cache/SiteConfigCacheWarmer.php:6-7, 18-25` | Define a publication-tree query port and a separate refresh/ingestion port. Do not expose newsroom graph tables through the package API. |
| Profile metadata and comments | `Theme/ContextBuilder.php` | Define profile metadata and comment providers returning package DTOs; the newsroom supplies adapters for its Redis and Doctrine implementations. |
| Markdown conversion | `Theme/ContextBuilder.php:10, 20-25` | Use a bundle-owned conversion contract. The newsroom CommonMark service becomes its adapter. |
| Lightning zap services | `src/Unfold/ZapApiController.php:5-30` | Keep the current LNURL/signing/QR implementation host-owned for this increment. The package retains only optional invoice contracts for a future clean adapter. |
| Administration and subscription operations | `src/Controller/Administration/UnfoldSiteController.php:30-40`, `src/Service/PublicationSubdomainService.php` | These remain host-owned for now. Phase 3b/7 must move creator administration only after `PublicationContext` is established; platform billing and moderation remain in the host as specified by `Specs/00-refactor-plan.md:108-117`. |
| Main-site discovery/preview | `src/Twig/Components/Organisms/FeaturedUnfoldSites.php`, `MagazinePreview.php`, `src/Service/Admin/AdminDashboardService.php` | Host-owned discovery and operator dashboards should use the site registry adapter, not reach into package persistence. |

## Runtime configuration

| Input | Current source | Extraction requirement |
| --- | --- | --- |
| Base domain | `config/services.yaml:76-79`; injected into `UnfoldRequestListener.php:44-48` | Retain as bundle configuration, with a host-provided value. |
| `UNFOLD_HOST` | `config/services.yaml:76-79` | No bundle consumer was found. Determine whether it belongs to host Caddy/proxy configuration or remove it before package extraction. |
| Themes directory | `DependencyInjection/Configuration.php` | The default resolves from the package path and `unfold.themes_path` remains host-overridable. |
| Cache pool | `DependencyInjection/Configuration.php:27-30` | Retain as a configurable service ID; the host supplies the implementation. |
| Theme path consumers | `Theme/HandlebarsRenderer.php`, `Controller/ThemeAssetController.php:19-24`, `src/Controller/Administration/UnfoldSiteController.php` | Inject the resolved theme path/configuration rather than rebuilding a source-tree path. |
| Route loading | `config/routes.yaml:7-11`, `config/routes/unfold.yaml:1-7` | The package must expose route resources; host import configuration becomes package installation wiring. Preserve ordering before the `/{path}` catch-all in `Resources/config/routes.yaml:37-45`. |
| Service loading | `config/services.yaml` and package `Resources/config/services.yaml` | Package classes are loaded by the bundle extension; only host adapters are discovered by the app. |
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
| Zap invoices | The optional `ZapInvoiceServiceInterface` remains package-facing, but the current NIP-57 endpoint stays host-owned until a clean adapter can be introduced without leaking newsroom services. |
| Creator administration | The package will own publication-owner administration after `PublicationContext` is implemented. DN platform moderation and billing remain host-only. |

The current loader mixes direct relay reads with an `EventRepository` fast path
(`Config/SiteConfigLoader.php:141-189`), while `ContentProvider` already
supports the selected optional-graph model (`ContentProvider.php:25-30, 60-80`).
`UnfoldSiteController` must not move mechanically because it mixes platform
`ROLE_ADMIN` operations, host event persistence, and browser signing.

## Implemented internal package increment

The contracts now live in `packages/unfold-bundle/src/Contract/`:
`SiteRegistryInterface`, `PublicationSite`, `NostrEvent`,
`EventReadGatewayInterface`, `PublicationTreeLookupInterface`,
`MarkdownConverterInterface`, `ProfileMetadataProviderInterface`, `ProfileMetadata`,
`CommentProviderInterface`, `Comment`, `PublicationRefreshInterface`,
`ZapInvoiceRequest`, `ZapInvoice`, and `ZapInvoiceServiceInterface`.
The newsroom host adapters now live in `src/Unfold/`: `SiteRegistryAdapter`
maps `UnfoldSite` records to `PublicationSite`, `EventReadGatewayAdapter`
provides database-first coordinate reads with relay fallback, and
`PublicationTreeLookupAdapter` maps ordered graph rows to `NostrEvent` DTOs.
Symfony aliases wire these adapters to the three package interfaces.

`SiteConfigLoader`, `ContentProvider`, and cache warming now consume package
contracts. Profile metadata, comments, markdown conversion, and publication
refreshing are adapted in `src/Unfold/`. The Doctrine entity listener and
administration controller remain host-owned. Zap endpoints remain host-owned
because the existing newsroom zap implementation has no clean invoice service
adapter yet.

The root application consumes `decent-newsroom/unfold-bundle` through the
`packages/unfold-bundle` symlink path repository at `@dev`. The package uses the
`DecentNewsroom\UnfoldBundle\` namespace and owns its routes, Twig view, themes,
services, and specs. No package class imports `App\`, and no runtime path points
at the former source-tree location.

Remaining deliberate seams are the host Doctrine site registry, event/graph
storage, profile/comment/markdown adapters, publication refresh adapter, host
administration, and optional zap API. These are explicit DI contracts rather
than package dependencies. Docker Composer validation, cache/container
compilation, router inspection, PHP linting, package tests, adapter tests, and
rendering tests have completed successfully.

Implement `PublicationContext` before moving creator administration. The later
extraction must satisfy the Phase 7 direction to introduce bundle-owned
interfaces plus host adapters before following the package extraction process
(`Specs/00-refactor-plan.md:172-179`).
