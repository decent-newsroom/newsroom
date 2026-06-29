# Article Preview Cards

## Overview

Article lists and locally resolved article references share the
`Molecules:Card` Twig component. This keeps article layout, metadata, cover
images, responsive behavior, routing, and bookmark controls consistent across
the application.

## Rendering paths

### Article lists

Discover's Recent and Featured Writers tabs render
`Organisms:CardList`, which delegates each item to `Molecules:Card`.

### Nostr article references

A locally resolved kind `30023` `naddr` request is handled by
`DefaultController::handleNaddrPreview()`. It loads the `Article` entity and
renders `templates/components/Molecules/ArticlePreview.html.twig`.

`ArticlePreview.html.twig` is intentionally a thin adapter:

```twig
<twig:Molecules:Card :article="article" />
```

Highlight feed cards use this `naddr` preview path for their referenced
articles. As a result, the article displayed below a highlight uses the same
component and styles as Discover's Recent and Featured Writers cards.

Bare long-form `naddr` links rendered from article content also use the shared
card through the CommonMark converter.

## Fallback behavior

If the referenced article is not available in the local database, the preview
endpoint keeps the existing fetch/view fallback. It does not construct a
partial article card from incomplete Nostr metadata.

## Maintenance

Make article-card markup and visual changes in:

- `templates/components/Molecules/Card.html.twig`
- `assets/styles/03-components/card.css`

Keep `ArticlePreview.html.twig` as an adapter so all article-reference contexts
inherit future card changes automatically.
