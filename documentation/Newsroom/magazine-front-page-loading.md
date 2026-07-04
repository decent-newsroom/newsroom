# Magazine front page loading behavior

## Goal

Improve perceived performance and reduce timeouts when opening magazine front pages.

## Behavior

- If a magazine has a top-level article reference (`a` tag to kind `30023`/`30024`) on its index event, the front page renders as a **featured-article view only**.
- In that case, category previews are not rendered on the main magazine page.
- The featured article body is loaded via a lazy Turbo Frame (`magazine-front-article-frame`) so the hero renders immediately.

- If a magazine has no top-level featured article and only category references, the front page shows **one cached article preview per category**.
- Category preview payload (category title/slug + first article coordinate) is cached for 10 minutes.
- The category preview section is loaded via Turbo Frame (`magazine-categories-frame`) so initial page response is fast even when cache is cold.

## Implementation points

- `src/Controller/DefaultController.php`
  - `magIndex()` now decides between:
    - featured article shell view (`magazine-front-article.html.twig`), or
    - category-preview front view (`magazine-front.html.twig`).
  - `magFrontArticleFrame()` resolves and renders the featured article asynchronously.
  - `magCategoriesFrame()` now builds/reads cached per-category preview payload and resolves article cards in batch.

- `templates/magazine/magazine-front-article.html.twig`
  - Uses a lazy Turbo Frame to load featured article content.

- `templates/magazine/_front_article_frame.html.twig`
  - Renders featured article markup (or fallback if unavailable).

- `templates/magazine/_categories_frame.html.twig`
  - Renders one preview card per category from cached payload.

