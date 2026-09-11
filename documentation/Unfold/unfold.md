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

## App Data Schema

Magazine data is stored as a Nostr kind 30078 event (NIP-78, arbitrary app data) with a structured JSON payload containing site configuration, theme settings, and content mappings.

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
