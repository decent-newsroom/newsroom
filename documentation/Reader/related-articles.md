# Related Articles Suggestions

## Overview

When a user finishes reading an article, the bottom of the page (below the comments section) shows up to three related article suggestions. The suggestions are now lazy-loaded through a dedicated Turbo Frame endpoint, so the main article response is not blocked by related-content discovery. The suggestions remain context-aware: for logged-in users whose interests overlap with the article's tags, results are narrowed to that intersection; for anonymous users or when there is no overlap, the article's own tags are used.

## How It Works

### Component

`RelatedArticles` (`src/Twig/Components/Organisms/RelatedArticles.php`) is a standard (non-Live) Twig component rendered inside a lazy Turbo Frame partial returned by `ArticleController::articleRelatedFrame()`.

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
2. **Fallback (no intersection or anonymous):** Use `ContentSearchService::findRelatedArticles()` with the article's own tags and label the section "Related articles".
3. **Search:** When interests overlap, the component calls `ContentSearchService::searchByTopics()` with the intersected tags. Otherwise it uses `ContentSearchService::findRelatedArticles()`. The current article is excluded by matching on pubkey + slug from the coordinate.
4. **Delivery:** `templates/pages/article.html.twig` renders an empty `<turbo-frame id="article-related-articles">` whose `src` points to `article-related-frame`; the frame response then renders the Twig component.
5. **Empty state:** If no related articles are found, the frame stays empty.

### Template

`templates/components/Organisms/RelatedArticles.html.twig` renders a grid of `<twig:Molecules:Card>` components, following the same visual pattern as the reading list prev/next navigation.

### Placement

The frame placeholder is inserted in `templates/pages/article.html.twig` between the Comments section and the mobile highlights section. It only renders for non-draft articles that have at least one topic tag.

```twig
<turbo-frame id="article-related-articles"
    src="{{ path('article-related-frame', {npub: npub, slug: article.slug}) }}"
    loading="lazy">
</turbo-frame>
```

## Styles

`assets/styles/03-components/related-articles.css` — mirrors the `article-prev-next` pattern with a responsive grid layout. No rounded edges, no shading.

## Files

| File | Role |
|------|------|
| `src/Controller/Reader/ArticleController.php` | Turbo Frame endpoint (`article-related-frame`) |
| `src/Twig/Components/Organisms/RelatedArticles.php` | Component logic |
| `templates/components/Organisms/RelatedArticles.html.twig` | Component template |
| `templates/pages/article.html.twig` | Article page (Turbo Frame placeholder) |
| `templates/pages/_article_related_frame.html.twig` | Turbo Frame partial |
| `assets/styles/03-components/related-articles.css` | Styles |
| `assets/app.js` | CSS import |
| `translations/messages.*.yaml` | Translation keys (`article.relatedArticles`, `article.relatedFromInterests`) |

