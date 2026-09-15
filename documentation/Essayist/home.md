# Essayist home

`GET /essayist/home` is the personalized reading page for `ROLE_ESSAYIST_MEMBER` users and admins. Anonymous users are redirected to the landing page with `join_status=login_required`; other users receive `access_denied`.

## Tabs

`EssayistController::homeFeedTab()` accepts four tabs at `/essayist/home/tab/{tab}`.

| Tab | Source |
|---|---|
| `foryou` | Merges followed authors and matching topic articles, deduplicated by author and slug. |
| `follows` | Articles from the user's kind 3 follow list, with profile-service backfill when the local list is missing. |
| `topics` | Articles matching kind 10015 interest hashtags. |
| `activity` | Recent highlights (9802), reposts (16), and comments (1111) by current members, read from the local event repository. |

The article tabs use `EssayistFeedService` against the internal Essayist relay. The activity tab uses `EssayistMemberActivityService`; it does not expand article reads to the member relay pool. Activity highlights share the normal highlight/source templates and resolve available referenced-article previews.

## Rendering and live updates

The page uses `content--home-tabs`, Turbo Frame partials in `templates/essayist/tabs/`, and `target="_top"` for navigation out of the frame. The configured `ESSAYIST_WRITERS` follow pack supplies sidebar writers.

Opening the home page activates its relay-feed updates. `POST /essayist/home/keepalive` maintains that activity; the sidebar renders the latest relay articles. The full unfiltered feed remains at `/essayist/feed`.

## Implementation

- [Controller](../../src/Controller/EssayistController.php)
- [Article feed](../../src/Service/Essayist/EssayistFeedService.php)
- [Member activity](../../src/Service/Essayist/EssayistMemberActivityService.php)
- [Home template](../../templates/essayist/home.html.twig)
- [Membership and relay architecture](essayist.md)
