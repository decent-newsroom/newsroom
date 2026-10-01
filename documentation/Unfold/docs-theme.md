# Unfold docs theme

The `docs` theme presents an Unfold publication as documentation instead of a blog. It is available anywhere a publication theme is selected:

- During magazine onboarding.
- In publication settings at the subdomain or main-domain publication admin mount.
- In the operator's Unfold site create and edit forms.

Selecting it saves `docs` as the publication's local theme setting. Requests to that publication's registered subdomain load the saved setting before routing and render all public pages from `packages/unfold-bundle/Resources/themes/docs/`.

## Layout

The theme uses:

- A persistent category sidebar with Home and About links.
- Compact article indexes on the home and category pages, without blog-card cover images.
- A central reading column for article and About pages.
- A desktop table of contents on articles.

The layout becomes a single column at narrower viewport widths. It intentionally has no rounded corners or shaded cards.

## Article headings and table of contents

The article page includes `docs.js`, which progressively enhances rendered article HTML. It reads `h2` and `h3` elements in the article content, assigns unique URL anchors, and builds the adjacent "On this page" navigation. The table of contents stays hidden when an article has no such headings.

Articles remain fully readable without JavaScript; headings are only linked after progressive enhancement.

## Theme assets and extension

The theme owns its CSS and documentation-only enhancement assets under `Resources/themes/docs/assets/`, served through `/unfold-themes/docs/{asset}`. The current zap interaction and KaTeX initializer reuse the established default-theme JavaScript implementations, while the docs theme provides its own square, unshaded zap styles. Theme templates use this explicit route because the renderer's `@` context variables are not resolved by LightnCandy in ordinary template expressions.

To extend the theme, keep its `index.hbs`, `category.hbs`, `post.hbs`, `about.hbs`, and shared partials compatible with the existing `ContextBuilder` fields. Adding a directory with an `index.hbs` causes the renderer to discover it automatically; `PublicationSettingsManager` then accepts it as a selectable value.
