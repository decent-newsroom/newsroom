# Unfold (Hosted Magazines)

## Overview

Unfold is a self-contained bundle (`packages/unfold-bundle/`) that renders magazines on custom subdomains. Each `UnfoldSite` entity maps a subdomain to a magazine coordinate, and the bundle handles routing, theming, and content rendering.

## Architecture

| Component | File |
|-----------|------|
| Bundle | `packages/unfold-bundle/src/UnfoldBundle.php` |
| Site controller | `packages/unfold-bundle/src/Controller/SiteController.php` |
| Theme controller | `packages/unfold-bundle/src/Controller/ThemeAssetController.php` |
| Request listener | `packages/unfold-bundle/src/EventListener/UnfoldRequestListener.php` |
| Host resolver | `packages/unfold-bundle/src/Http/HostResolver.php` |
| Route matcher | `packages/unfold-bundle/src/Http/RouteMatcher.php` |
| Config loader | `packages/unfold-bundle/src/Config/SiteConfigLoader.php` |
| Content provider | `packages/unfold-bundle/src/Content/ContentProvider.php` |
| Context builder | `packages/unfold-bundle/src/Theme/ContextBuilder.php` |
| Handlebars renderer | `packages/unfold-bundle/src/Theme/HandlebarsRenderer.php` |
| SWR cache | `packages/unfold-bundle/src/Cache/StaleWhileRevalidateCache.php` |

## Dynamic Subdomain Routing

The `UnfoldRequestListener` intercepts requests, checks if the hostname matches a configured subdomain, and routes to the Unfold controllers. The `base_domain` parameter (from `BASE_DOMAIN` env) determines what constitutes a subdomain.

## Publication Identity And Settings

An Unfold is permanently identified by one root `30040:<pubkey>:<dtag>` magazine
coordinate. The index events supply publication content and navigation. Local
settings, keyed by that coordinate, persist the selected theme separately from
subdomain hosting and subscription billing. Existing sites without settings use
the default theme.

[Setup and local settings](site-creation-signing.md) work without fetching,
signing, or publishing kind `30078`. Legacy AppData loading is compatibility-only.
A custom portable definition event remains planned; current owner administration
uses the bundle's implemented publication context.

## Theming

Themes use Handlebars templates rendered by `HandlebarsRenderer`. Theme assets are served by `ThemeAssetController` from the `publication/` directory.

## Caching

`StaleWhileRevalidateCache` serves cached content immediately while refreshing in the background. Warm-up runs every 30 minutes via cron.

The `SiteConfigCacheWarmer` performs the following steps when warming:

1. **Network refresh**: Fetches the latest magazine and category events from relays and ingests them into the local DB + graph tables (`current_record`, `parsed_reference`). This ensures the graph layer has up-to-date references before cache population.
2. **SiteConfig reload**: Invalidates and reloads the SiteConfig from the freshly updated DB.
3. **Content cache warm**: Invalidates and repopulates the SWR cache for categories, category posts, and home posts using the graph layer (now guaranteed to be current).

## Zaps on Unfold Pages

Unfold pages include basic zap invoice support — resolving the magazine author's `lud16` address and displaying a Lightning QR code.

## Package boundary

The application consumes `decent-newsroom/unfold-bundle` through the local
`packages/unfold-bundle` Composer path repository at `@dev`. Its namespace is
`DecentNewsroom\UnfoldBundle`. Package code owns rendering, theme resources,
route/service configuration, cache orchestration, and publication administration.

The package uses contracts in `src/Contract/` relative to the package root.
Newsroom supplies adapters in `src/Unfold/` for the site registry, local settings,
event reads, publication tree lookup/refresh, profile metadata, comments, markdown
conversion, and administrator identity. The `UnfoldSite` Doctrine entity,
event/graph storage, subscription billing, and platform moderation remain
host-owned. The optional zap invoice contracts do not move Newsroom's current
LNURL endpoint into the package.

`PublicationContext` and owner administration are implemented; see
[publication administration](publication-admin.md). The earlier prerequisite to
create that context is complete. Independent distribution and standalone-host
validation remain future work, separate from the completed internal Composer
extraction. Package specs in `packages/unfold-bundle/Specs/` record planned
extensions and remaining portability work.

Hosts supply cache pools and writable compiled-template cache storage; themes
remain read-only package resources. Cron scheduling and container configuration
stay in the host application.
