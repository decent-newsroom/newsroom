# Featured Unfold Sites

## Overview

Unfold-hosted magazine subdomains are featured as premium content at the top of the articles/discover page and the authenticated home feed. This gives publications that are hosted on Decent Newsroom subdomains prominent visibility as curated, high-quality content.

## Architecture

### Component

`App\Twig\Components\Organisms\FeaturedUnfoldSites` — a Twig Live Component that:

1. Queries all `UnfoldSite` entities from the database (ordered by creation date, newest first)
2. Resolves each site's magazine event (kind `30040`) to extract title, summary, and cover image
3. Constructs the external URL from the subdomain and base domain
4. Caches the resolved list in Symfony's cache pool for 15 minutes

### Template

`templates/components/Organisms/FeaturedUnfoldSites.html.twig` renders a responsive grid of cards, each showing:

- Magazine cover image (if available)
- Magazine title
- Summary (truncated to 140 characters)
- "Visit site" link to the hosted subdomain

### Styling

`assets/styles/03-components/featured-unfold.css` — follows the project's design rules:

- No shading
- No rounded edges
- Uses CSS variables for colors, spacing, and typography
- Responsive grid: auto-fill columns on desktop, single column on mobile
- Hover effect: border color changes to primary

### Integration Points

The component is included in two templates:

- **Discover page** (`templates/pages/discover.html.twig`) — above the article list
- **Authenticated home** (`templates/home_authenticated.html.twig`) — above the feed tabs

### Data Flow

```
UnfoldSite (DB) → FeaturedUnfoldSites component
                    ├── UnfoldSiteRepository::findBy()
                    ├── Event repository (kind 30040, match pubkey + slug)
                    ├── Extract title/summary/image from magazine event
                    └── Cache result for 15 minutes
```

### Fallback Behavior

- If no `UnfoldSite` records exist, the section is hidden entirely
- If a magazine event hasn't been cached locally yet, the subdomain name is used as the title
- The component gracefully handles invalid coordinates with a warning log

## Translations

Translation keys under `featured_unfold.*`:

| Key | Description |
|-----|-------------|
| `featured_unfold.label` | "Premium" label above the heading |
| `featured_unfold.heading` | Section heading |

Available in: en, de, es, fr, sl

## Cache

Results are cached using Symfony's `CacheInterface` with key `featured_unfold_sites` and a 15-minute TTL. The cache is automatically invalidated on expiry. To manually clear:

```bash
docker compose exec php bin/console cache:pool:clear cache.app
```

