# Unfold (Hosted Magazines)

## Overview

Unfold is a self-contained bundle (`src/UnfoldBundle/`) that renders magazines on custom subdomains. Each `UnfoldSite` entity maps a subdomain to a magazine coordinate, and the bundle handles routing, theming, and content rendering.

## Architecture

| Component | File |
|-----------|------|
| Bundle | `src/UnfoldBundle/UnfoldBundle.php` |
| Site controller | `src/UnfoldBundle/Controller/SiteController.php` |
| Theme controller | `src/UnfoldBundle/Controller/ThemeAssetController.php` |
| Request listener | `src/UnfoldBundle/EventListener/UnfoldRequestListener.php` |
| Host resolver | `src/UnfoldBundle/Http/HostResolver.php` |
| Route matcher | `src/UnfoldBundle/Http/RouteMatcher.php` |
| Config loader | `src/UnfoldBundle/Config/SiteConfigLoader.php` |
| Content provider | `src/UnfoldBundle/Content/ContentProvider.php` |
| Context builder | `src/UnfoldBundle/Theme/ContextBuilder.php` |
| Handlebars renderer | `src/UnfoldBundle/Theme/HandlebarsRenderer.php` |
| SWR cache | `src/UnfoldBundle/Cache/StaleWhileRevalidateCache.php` |

## Dynamic Subdomain Routing

The `UnfoldRequestListener` intercepts requests, checks if the hostname matches a configured subdomain, and routes to the Unfold controllers. The `base_domain` parameter (from `BASE_DOMAIN` env) determines what constitutes a subdomain.

## App Data Schema

Magazine data is stored as a Nostr kind 30078 event (NIP-78, arbitrary app data) with a structured JSON payload containing site configuration, theme settings, and content mappings.

## Theming

Themes use Handlebars templates rendered by `HandlebarsRenderer`. Theme assets are served by `ThemeAssetController` from the `publication/` directory.

## Caching

`StaleWhileRevalidateCache` serves cached content immediately while refreshing in the background. Warm-up runs every 30 minutes via cron.

## Zaps on Unfold Pages

Unfold pages include basic zap invoice support — resolving the magazine author's `lud16` address and displaying a Lightning QR code.

