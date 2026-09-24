# Footer and pricing

The footer links to the product pages, subscriptions, Essayist, MCP, support, contact, and sitemap. Its community section includes the repository, changelog, roadmap, and profile links.

## Implementation

- [Footer template](../../templates/components/Footer.html.twig)
- [Footer component](../../src/Twig/Components/Footer.php)
- [Layout styles](../../assets/styles/02-layout/layout.css)
- [Pricing template](../../templates/static/pricing.html.twig)
- [Subscription styles](../../assets/styles/04-pages/subscription.css)

Brand values come from Twig configuration; visible labels use translation keys. Keep the template and styles as the source for the current layout rather than duplicating a column-by-column design snapshot here.

## Pricing entry points

`StaticController::pricing()` serves `/pricing` with route name `app_static_pricing`.

| Offering | Displayed price | Details |
|---|---|---|
| Reader | Free | Public reading and discovery. |
| Vanity name | 5,000 sats per quarter | [Vanity names](vanity-names.md) |
| Unfold hosting | 120,000 sats per year | [Publication subdomains](publication-subdomain.md) |

The pricing template contains the displayed amounts; the relevant subscription service defines purchase behavior. Keep both in sync when changing an offering.
