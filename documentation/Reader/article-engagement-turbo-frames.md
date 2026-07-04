# Article engagement turbo frames

## Goal

Load article comments and highlights after the main article body is already visible, reducing time-to-content on article pages.

## What changed

- Added lazy Turbo Frame endpoint `article-comments-frame` at `/p/{npub}/d/{slug}/comments`.
- Added lazy Turbo Frame endpoint `article-highlights-frame` at `/p/{npub}/d/{slug}/highlights`.
- Updated `templates/pages/article.html.twig` to load both sections via `<turbo-frame loading="lazy">`.
- Extracted comments markup to `templates/pages/_article_comments_frame.html.twig`.
- Extracted highlights markup to `templates/pages/_article_highlights_frame.html.twig`.
- Added `assets/controllers/content/highlights_frame_controller.js` to emit loaded highlight payloads.
- Updated `assets/controllers/ui/highlights_toggle_controller.js` to apply highlights when the lazy frame arrives.

## Notes

- Main article rendering no longer fetches highlights synchronously in `authorArticle`; highlights are fetched only by the lazy highlights frame endpoint.
- Highlights toggle action in the article actions dropdown remains available even before the frame loads.

