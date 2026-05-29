# Essayist Home Activity Tab

## What this adds

A new **Activity** tab is available on `/essayist/home`.

It shows recent events produced by current Essayist members (the membership pool), focused on:

- highlights (`kind:9802`)
- reposts (`kind:16`)
- comments (`kind:1111`)

## Implementation

- `EssayistController::homeFeedTab()` now accepts `activity` in the tab route and renders `templates/essayist/tabs/_activity.html.twig`.
- New service: `App\Service\Essayist\EssayistMemberActivityService`.
  - Resolves current member pubkeys from users with `ROLE_ESSAYIST_MEMBER`.
  - Fetches recent events for those authors from local DB (`EventRepository::findByFilter`).
  - Classifies each event into `highlight`, `repost`, or `comment`.

## Notes

- `EssayistFeedService` remains single-relay and exclusive-content-only.
- The Activity tab uses the member pool for author scope, not feed-service relay fan-out.
- Activity highlight items now reuse the same card markup as `/highlights` via `templates/partial/_highlight_feed_card.html.twig`.
- Activity highlight processing now generates `naddr` references (for addressable `a`/`A` tags) and parses preview payloads so referenced articles render as preview cards when resolvable.
- Highlights in the Activity tab now render via the shared highlight/source template (`templates/partial/_highlight_with_source.html.twig`) so source references (`a`/`r` tags) are shown consistently.
- Essayist home tab frames now use `target="_top"`, so links clicked inside tab content open full pages instead of navigating inside the frame.


