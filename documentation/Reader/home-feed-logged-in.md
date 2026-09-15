# Home Feed for Logged-In Users

Signed-in users see a personalized feed at `/`; anonymous users see the public
landing page.

| Tab | Source |
|---|---|
| Articles (default) | Discussed articles, followed authors, and interest topics merged by coordinate |
| Follow Pack | The user's featured kind `39089` follow pack |
| Activity | Highlights (kind `9802`) and long-form comments (kind `1111`) from followed users |
| Updates | The user's `UpdateSubscription` sources, without marking updates read |

The Articles feed preserves source labels and comment counts, sorts newest-first,
and limits the merged result to 60. It applies configured exclusions and the
user's mute list. Follow lists are read from the latest local kind `3` event,
with `UserProfileService::getFollows()` backfill when absent. Interest articles
use the selected search implementation.

A missing featured pack shows a setup notice. Activity has an empty state for
readers without follows; Updates links to subscription management when there are
no subscribed sources.

## Routes and rendering

- `DefaultController::index()` selects `home_authenticated.html.twig`.
- `HomeFeedController::tab()` returns Turbo Frame content for `articles`,
  `foryou` (the same Articles feed), `featuredpack`, `activityfeed`, and
  `updatesfeed` at `/home/tab/{tab}`.
- `content--home-tabs` switches tabs and updates `home-tab-content`.
- The initial Articles frame loads lazily and uses the
  [browser article-list cache](pwa-article-list-caching.md).

The former Latest, Follows, Interests, Discussed, and Media tab names still
appear in the route requirement, but their dispatch branches are commented out.
They are not working compatibility endpoints and must not be linked or
prefetched. Podcasts and News Bots are not current home tabs.

## Related features and files

Follow-pack source administration is documented in
[follow pack setup](../Newsroom/follow-pack-setup.md); its platform source
assignments are separate from the user's featured pack. The standalone
[Following page](follows-feature-implementation.md) remains available.

- `templates/home_authenticated.html.twig` - tab shell
- `templates/home/tabs/` - frame partials
- `assets/controllers/content/home_tabs_controller.js` - navigation
- `assets/styles/04-pages/home-feed.css` - feed layout
- `assets/styles/03-components/source-badge.css` - article source labels
- `translations/messages.*.yaml` - `home_feed.*` copy
