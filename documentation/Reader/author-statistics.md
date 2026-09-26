# Asynchronous author statistics

## Overview

The authenticated `/stats` page renders immediately without querying visit statistics. Three Turbo Frames load weekly metrics, monthly metrics, and the traffic chart independently. The existing ROLE_ADMIN access requirement applies to the page and every section.

## Architecture

- `ProfileStatsController::index()` renders the shell.
- `/stats/week` eagerly loads weekly and 24-hour metrics, the weekly breakdown, and top articles.
- `/stats/month` lazily loads monthly metrics and top articles.
- `/stats/chart` lazily loads the last 30 calendar days.
- `VisitRepository::getAuthorStatsSummary()` aggregates each period in one query.
- `VisitRepository::getAuthorStatsChart()` aggregates daily views and distinct sessions in one query and fills missing days in PHP.

Rendering all sections uses five analytics queries instead of the previous 43. The shell uses none. The owner identifier always comes from the authenticated session, not request parameters.

Summary counts retain existing author-statistics semantics: broad profile-route matching includes bots and drafts in total views; article rankings and article breakdowns exclude draft suffixes. NULL session IDs do not count as unique visitors. Period-wide unique visitors are counted directly, never summed from daily counts. The chart uses 30 calendar dates, including today, with a half-open timestamp range from the first midnight to tomorrow's midnight.

## Loading and failures

Each frame has translated loading, unavailable, and retry states. A failed frame does not replace successful sections or turn unavailable statistics into zeros. Database exceptions are logged and return an HTTP 503 frame. The Stimulus controller also handles network errors and missing-frame proxy responses. Retry reloads only that section.

A successful same-origin redirect to a page without the expected frame navigates the whole page, supporting expired-session login redirects. Personalized responses are private and no-store; the stats page disables Turbo snapshot caching.

The chart controller creates its chart when inserted and destroys it on disconnect so frame replacement and navigation do not leak chart instances.

## Key files

- `src/Controller/User/ProfileStatsController.php`
- `src/Repository/VisitRepository.php`
- `templates/stats/`
- `assets/controllers/analytics/stats_frame_controller.js`
- `assets/controllers/analytics/author_stats_chart_controller.js`
- `assets/styles/04-pages/profile-stats.css`
- `tests/Unit/Controller/ProfileStatsControllerTest.php`
- `tests/Unit/ProfileStatsTemplateTest.php`
- `tests/Service/AuthorStatsRepositoryTest.php`

## Configuration and limitations

No new worker, cache, package, or migration is required. Migration `Version20260925120000` already defines the route/visit-time index. Confirm deployed migrations and inspect query plans when diagnosing production latency; index usefulness for prefix matching depends on database collation.

Turbo defers HTTP requests, not SQL execution into background workers. Each section still performs synchronous, consolidated database queries. If measured production latency remains excessive, the next step is deduplicated Messenger refreshes of private per-user snapshots, with bounded polling and no expensive request-time fallback.
