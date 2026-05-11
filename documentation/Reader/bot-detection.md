# Bot Traffic Detection & Analytics Differentiation

## Overview

Bots, crawlers and scrapers used to be recorded as regular visits, inflating all analytics numbers. This feature adds **bot-traffic differentiation**: every HTTP request is inspected on arrival, flagged as `is_bot = true/false`, and analytics queries are scoped to human visits only.

## How It Works

### Detection (`BotDetector` service)

`src/Service/Analytics/BotDetector.php` checks the incoming `User-Agent` header against a curated list of ~60 patterns covering:

- Major search-engine crawlers (Googlebot, Bingbot, Yandex, Baidu, DuckDuckBot, …)
- Social-media preview bots (Facebook, Twitter, Slack, Discord, WhatsApp, Pinterest, …)
- SEO / auditing tools (Ahrefs, SEMrush, Moz, Screaming Frog, …)
- HTTP libraries & CLI tools (Python-requests, curl, wget, Go HTTP client, Scrapy, …)
- Headless browsers (PhantomJS, Selenium, Puppeteer, Playwright, …)
- Uptime / monitoring tools (UptimeRobot, Pingdom, GTmetrix, …)
- Empty / missing User-Agent strings (unconditionally flagged as bots)

### Storage

Two new columns on the `visit` table (migration `Version20260511120000`):

| Column | Type | Notes |
|---|---|---|
| `user_agent` | `VARCHAR(512) NULL` | Sanitised (null-bytes removed, capped at 512 chars) |
| `is_bot` | `BOOLEAN NOT NULL DEFAULT false` | Set by `BotDetector` at request time |

Indexed on `(is_bot)` and `(is_bot, visited_at)` for fast analytics queries.

### Listener (`VisitTrackingListener`)

`onKernelRequest` was extended to:
1. Call `BotDetector::isBot($userAgent)`.
2. Store the sanitised UA string in the `Visit` entity.
3. Set `is_bot = true` when detected — the row is still written, so bot volume is visible.
4. Skip issuing a tracking cookie (`_nv`) to detected bots (no point creating one).

### Queries (`VisitRepository`)

`applyTrackedVisitFilters()` now appends `AND is_bot = false` to **every** standard analytics query, so all existing metrics (total visits, unique visitors, bounce rate, visits-per-day, top routes, referrers, …) automatically reflect human traffic only.

Raw-SQL methods (`getVisitsPerDay`, `getBounceRate`, `getSubdomainVisitsPerDay`) were updated individually with `AND is_bot = false`.

New bot-specific methods added:

| Method | Description |
|---|---|
| `countBotVisitsSince(?since)` | Total bot hits in a time window |
| `getTopBotUserAgents($limit, ?since)` | Most frequent bot UA strings |
| `getBotVisitsPerDay($days)` | Bot traffic time series |
| `getBotVsHumanStats()` | Bot vs human counts + % for 24 h / 7 d / 30 d |

## Admin Dashboard

The `/admin/analytics` page gained a **Bot Traffic** section showing:

- **Bot vs Human** comparison cards for last 24 h, 7 d and 30 d with a bot-share percentage that turns red when bots exceed 50 %.
- **Bot Traffic Per Day** chart (last 30 days) — reuses the existing `analytics--visits-per-day-chart` Stimulus controller.
- **Top Bot User-Agents** table (last 7 days) — shows which crawlers are hitting the site most.
- The **Recent Visits** table now includes a truncated `User-Agent` column for spot-checking.

A note below the UA table explains that bot visits are excluded from all other metrics.

## Extending the Bot List

Add new patterns to `BotDetector::BOT_PATTERNS`. The array is case-insensitive substring-matched against the full UA string. No other change is required — detection logic, storage, and query filtering are all wired together automatically.

## Known Limitations

- Detection is UA-string based only. A sophisticated bot that spoofs a realistic browser UA will not be caught. For stricter enforcement, pair this with rate-limiting at the reverse-proxy (Caddy) level.
- Historical visits (before this feature was deployed) have `is_bot = false` (the column default), meaning they are counted as human traffic regardless. Run a one-time backfill if precise historical numbers matter.

## Caddy-level hard-block (probe paths)

A second, complementary defence is configured in `frankenphp/Caddyfile`. Caddy matches a curated blocklist of known **scanner / attacker probe paths** via a single `path_regexp` matcher and responds with `404` before FrankenPHP even starts a PHP worker. No Symfony routing, no DB write, no visit-tracking overhead at all.

Covered patterns (see `@blockProbes` in the Caddyfile for the exact regex):

| Category | Examples |
|---|---|
| Env / secrets files | `/.env`, `/.env.local`, `/.env.production`, … |
| VCS metadata | `/.git/*`, `/.svn/*`, `/.hg/*` |
| Server config | `/.htaccess`, `/.htpasswd`, `/web.config` |
| PHP info / install | `/phpinfo.php`, `/info.php`, `/install.php`, … |
| Composer manifests | `/composer.json`, `/composer.lock` |
| WordPress probes | `/wp-login.php`, `/xmlrpc.php`, `/wp-admin/*`, … |
| Admin tools | `/adminer.php`, `/phpmyadmin/*` |
| Spring actuator | `/actuator/*` |

To add more paths, extend the regex in the `@blockProbes path_regexp` line in `frankenphp/Caddyfile`.

