# Footer Redesign & Pricing Page

## Footer

The site footer uses a two-column grid layout on screens ≥768px, collapsing to a single column on mobile.

### Structure

| Column | Section | Contents |
|--------|---------|----------|
| Left | **Product** | About, Pricing, Terms of Service, Support, Contact |
| Right | **Community** | GitHub repository link, Changelog, Project npub (via `UserFromNpub`), Developer npub (via `UserFromNpub`) |
| Right | **Subscriptions** | Vanity Name, Active Indexing, Publication Subdomain |
| Full width | **Bottom** | Copyright line with year, brand name, and version |

### Technical Details

- **Template:** `templates/components/Footer.html.twig`
- **Component class:** `src/Twig/Components/Footer.php`
- **CSS:** `assets/styles/02-layout/layout.css` (footer section)
- **Twig globals used:** `project_npub`, `dev_npub`, `brand_name`, `brand_version` (defined in `config/packages/twig.yaml`)
- **Translation keys:** `footer.product`, `footer.community`, `footer.project`, `footer.developer`, `footer.pricing` (all 5 locales)

### Responsive Breakpoint

- `< 768px`: single column, stacked sections
- `≥ 768px`: two-column grid (`1fr 1fr`)

## Pricing Page

The `/pricing` route renders a single page showing all subscription tiers side-by-side.

### Route

- **URL:** `/pricing`
- **Route name:** `app_static_pricing`
- **Controller:** `StaticController::pricing()`
- **Template:** `templates/static/pricing.html.twig`

### Tiers Displayed

| Tier | Price | Links to |
|------|-------|----------|
| Reader | Free | Home page |
| Vanity Name | 5,000 sats/quarter | `/subscription/vanity` |
| Active Indexing | 1,000 sats/month | `/subscription/active-indexing` |
| Unfold Hosting | 120,000 sats/year | `/subscription/publication-subdomain` |

### CSS

Pricing overview card styles are in `assets/styles/04-pages/subscription.css` under the `/* ===== Pricing Overview Page ===== */` section.

Grid layout:
- `< 768px`: single column
- `768px – 1099px`: 2 columns
- `≥ 1100px`: 4 columns

### Translation Keys

All pricing text uses `pricing.*` keys from `translations/messages.{locale}.yaml`. Each tier has: `name`, `period`, `feature1`–`feature4`, and `cta`.

