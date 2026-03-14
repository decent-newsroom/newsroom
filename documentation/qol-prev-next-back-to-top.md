# Quality of Life Improvements: Prev/Next Navigation & Back to Top

## Back to Top Button

A floating "Back to top" button now appears in the bottom-right corner of every page once the user has scrolled more than 400px down. Clicking the button smoothly scrolls back to the top of the page.

### Implementation

- **Stimulus Controller**: `assets/controllers/ui/back_to_top_controller.js` — listens for scroll events, toggles visibility via a CSS class, and provides `scrollToTop()` action.
- **CSS**: `assets/styles/03-components/back-to-top.css` — fixed-position circular button with transition, respects theme variables.
- **Template**: Button markup added to `templates/layout.html.twig` so it appears globally on all pages.

The button uses a chevron-up SVG icon and is accessible (has `aria-label` and `title` attributes).

---

## Prev/Next Article Navigation

When viewing an article that belongs to a reading list (kind `30040`) or curation set (kind `30004`), navigation cards for the previous and next articles in that list are displayed at the bottom of the article page, below the content and above the comments.

### How It Works

1. **Service**: `App\Service\ReadingListNavigationService` performs a PostgreSQL JSON containment query to find reading lists/curation sets whose `a` tags reference the current article's coordinate (`30023:pubkey:slug`).
2. It resolves the article's position in the list and fetches the prev/next `Article` entities from the database.
3. Magazine index events (those referencing other 30040 events) are automatically skipped.
4. The first matching list with resolvable neighbors is used.

### Controllers Updated

- `ArticleController::authorArticle()` — passes `listNav` to the template.
- `DefaultController::magArticle()` — passes `listNav` to the template.

### Template

In `templates/pages/article.html.twig`, a `<nav class="article-prev-next">` section conditionally renders:
- A heading linking back to the reading list.
- A two-column grid with the existing `<twig:Molecules:Card>` component for prev and next articles.

### CSS

`assets/styles/03-components/article-nav.css` — responsive two-column grid layout that collapses to a single column on mobile (≤640px). The "Next" card is pushed to the right column when there's no previous article.

### Notes

- Navigation only appears for published articles (not drafts).
- If the article belongs to multiple lists, the most recently created one is used.
- If neither neighbor can be resolved from the database, the navigation section is hidden.

