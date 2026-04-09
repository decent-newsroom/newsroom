# Related Articles Suggestions

## Overview

When a user finishes reading an article, the bottom of the page (below the comments section) shows up to three related article suggestions. The suggestions are context-aware: for logged-in users whose interests overlap with the article's tags, results are narrowed to that intersection; for anonymous users or when there is no overlap, the article's own tags are used.

## How It Works

### Component

`RelatedArticles` (`src/Twig/Components/Organisms/RelatedArticles.php`) is a standard (non-Live) Twig component that resolves related articles during server-side rendering.

**Props:**
| Prop | Type | Description |
|------|------|-------------|
| `coordinate` | `string` | Current article's Nostr coordinate (e.g. `30023:pubkey:slug`) — used to exclude the current article from results |
| `topics` | `array` | Current article's tags/topics |
| `pubkey` | `string` | Current article's author pubkey (hex) |

**Computed:**
| Property | Type | Description |
|----------|------|-------------|
| `articles` | `Article[]` | Resolved related articles (max 3) |
| `fromInterests` | `bool` | Whether the suggestions come from user interest intersection |

### Resolution Strategy

1. **Logged-in user with interests:** Fetch the user's kind 10015 interest tags via `NostrClient::getUserInterests()`. Intersect with the current article's tags. If the intersection is non-empty, search by those tags and label the section "More from your interests".
2. **Fallback (no intersection or anonymous):** Use the article's own tags and label the section "Related articles".
3. **Search:** Calls `ArticleSearchInterface::findByTopics()` (Elasticsearch or database, factory-selected). The current article is excluded by matching on pubkey + slug from the coordinate.
4. **Empty state:** If no related articles are found, the component renders nothing.

### Template

`templates/components/Organisms/RelatedArticles.html.twig` renders a grid of `<twig:Molecules:Card>` components, following the same visual pattern as the reading list prev/next navigation.

### Placement

The component is inserted in `templates/pages/article.html.twig` between the Comments section and the mobile highlights section. It only renders for non-draft articles that have at least one topic tag.

```twig
<twig:Organisms:RelatedArticles
    coordinate="30023:{{ article.pubkey }}:{{ article.slug }}"
    :topics="article.topics"
    pubkey="{{ article.pubkey }}" />
```

## Styles

`assets/styles/03-components/related-articles.css` — mirrors the `article-prev-next` pattern with a responsive grid layout. No rounded edges, no shading.

## Files

| File | Role |
|------|------|
| `src/Twig/Components/Organisms/RelatedArticles.php` | Component logic |
| `templates/components/Organisms/RelatedArticles.html.twig` | Component template |
| `templates/pages/article.html.twig` | Article page (component placement) |
| `assets/styles/03-components/related-articles.css` | Styles |
| `assets/app.js` | CSS import |
| `translations/messages.*.yaml` | Translation keys (`article.relatedArticles`, `article.relatedFromInterests`) |

