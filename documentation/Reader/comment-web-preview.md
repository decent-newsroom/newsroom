# Web Preview for NIP-22 Comment External References

## Problem

NIP-22 comments (kind:1111) can be scoped to an external, non-Nostr resource via NIP-73 `I` (root) and `i` (parent) tags. A common shape is a web URL:

```
["I", "https://en.wikipedia.org/wiki/Sculpture"]
["K", "web"]
["i", "https://en.wikipedia.org/wiki/Sculpture"]
["k", "web"]
```

Before this change the single-event page (`/e/{nevent}`) rendered these as a plain `<a>` link, giving the reader no visual context for what the comment was about.

## Solution

The comment page renders a **placeholder card** for any `parentI` / `rootI` value that starts with `http://` or `https://`, showing the target hostname, the URL itself, and a **"Load preview"** CTA. Nothing is fetched from the third-party host until the reader explicitly clicks the CTA — the comment page never contacts arbitrary external servers on the reader's behalf without consent. Non-URL identity schemes (`podcast:item:guid:…`, `isbn:…`, …) fall back to a plain external link without any CTA.

### Components

- **`src/Twig/Components/Molecules/WebPreview.php`** + **`templates/components/Molecules/WebPreview.html.twig`** — `AsTwigComponent` that renders only the placeholder (host + URL + CTA). `mount()` does **no** network I/O.

- **`assets/controllers/utility/web_preview_controller.js`** — Stimulus controller (`utility--web-preview`) wired to the placeholder's CTA. On click it `fetch()`es the server endpoint and swaps the placeholder body for the rich card (image thumbnail + site name + title + description + URL, wrapped in an `<a target="_blank" rel="noopener noreferrer nofollow">`).

- **`src/Controller/WebPreviewController.php`** — `GET /api/web-preview?url=…` (route name `api_web_preview`). Validates the scheme is `http(s)`, delegates to `WebPreviewService::fetch()`, and returns `{url, host, title, description, image, siteName, ok}` as JSON. Response is browser-cacheable for 15 min (server-side cache is longer).

- **`src/Service/WebPreviewService.php`** — the actual fetcher. Streams the target URL with a 3 s timeout, reads up to 256 KiB of HTML (stopping early at `</head>`), and extracts preview metadata. Parsing priority:
    1. Open Graph (`og:title`, `og:description`, `og:image`, `og:site_name`)
    2. Twitter Card (`twitter:title`, `twitter:description`, `twitter:image`)
    3. `<title>` and `<meta name="description">`
    
    Relative image URLs are resolved against the page URL. Results are cached in the default Symfony app cache for 24 h (successful) or 15 min (failure / empty), keyed by `sha1(url)`. Because the service is only invoked via the opt-in endpoint, the cache is populated on demand, not speculatively.

- **`templates/event/_kind1111_comment.html.twig`** — replaces the bare link with `<twig:Molecules:WebPreview :url="..." />` for URL-shaped `I`/`i` values in both the "Replying to" (parent) and "In thread" (root) sections.

- **`assets/styles/03-components/web-preview.css`** — placeholder vs. rich card layout. No rounded corners, no shadow, consistent with project style.

- **`translations/messages.*.yaml`** — `webPreview.loadCta` and `webPreview.loadNotice` (with `%host%` placeholder) across en / de / es / fr / it / sl.

### Safety

- **No auto-fetch.** The rich preview is only requested after the user clicks "Load preview". This keeps third-party servers from seeing any request correlated with the reader's visit to the comment page until consent is given.
- Only `http`/`https` URLs are fetched.
- The HTTP client is capped at `timeout=3s`, `max_duration=4s`, `max_redirects=3`.
- Responses whose `Content-Type` doesn't contain `html` are skipped.
- The client stream stops once `</head>` or 256 KiB have been seen, then `cancel()`s the response.
- A custom `User-Agent` (`DecentNewsroomBot/1.0 (+https://decentnewsroom.com; web-preview)`) identifies the fetcher.

### Graceful degradation

If the fetch fails, times out, or yields no usable metadata, the component still renders a clickable link-card using the URL's hostname as the site label and the URL itself as the title. The negative result is cached for 15 min so repeated comment views don't retry hot.



