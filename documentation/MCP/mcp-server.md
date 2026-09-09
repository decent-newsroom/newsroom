# MCP server (articles and books)

A standalone [Model Context Protocol](https://modelcontextprotocol.io) server that
exposes the Decent Newsroom long-form article corpus and Nostr publication books
**read-only** to MCP-capable AI clients (Claude Desktop, Cursor, etc.).

It is a **fully independent service** living in `docker/mcp/` (alongside other
self-contained services like `docker/essayist-gateway/`) with
its own `composer.json`, `Dockerfile` and tests. It never touches PostgreSQL or
Elasticsearch directly — it reads all data through a token-guarded internal HTTP
APIs on the newsroom app, so the two can be developed and deployed independently.

## Architecture

```
[AI client] --stdio | HTTP (public)------------------> [mcp service]
     --internal HTTP (X-Internal-Token)--> [newsroom /internal/api/articles/*]
     --internal HTTP----------------------> [newsroom /books/api/*]
          --> article search / Books Elasticsearch alias
```

| Boundary | Secret | Enforced by |
|----------|--------|-------------|
| `mcp` → newsroom app | `INTERNAL_API_TOKEN` (`X-Internal-Token`) | `App\EventSubscriber\InternalApiTokenSubscriber` (fails closed) |

The MCP HTTP endpoint is public. The stdio transport has no network surface;
both transports use the internal-API token only for MCP-to-newsroom article
requests.

## Internal API contract (newsroom app)

Prefix: `/internal/api/articles` — reachable only on the internal Docker network
(`http://php`), never exposed through the public site. Every request must carry
`X-Internal-Token: <INTERNAL_API_TOKEN>`; otherwise it returns `401`.

| Endpoint | Query params | Returns |
|----------|--------------|---------|
| `GET /search` | `q`, `limit` (≤50), `offset` | `{ "results": [Article…] }` (no content) |
| `GET /get` | `coordinate` = `kind:pubkey:slug` | `{ "result": Article }` with full `content` |
| `GET /latest` | `limit` (≤50) | `{ "results": [Article…] }` |
| `GET /by-author` | `author` (hex or npub), `limit`, `offset` | `{ "results": [Article…] }` |
| `GET /by-topic` | `topics` (comma-separated), `limit`, `offset` | `{ "results": [Article…] }` |
| `GET /topics` | `topics` (comma-separated) | `{ "counts": { topic: n } }` |

Article shape (`InternalArticlePresenter`, stable/additive):

```json
{
  "coordinate": "30023:<hex>:my-slug",
  "kind": 30023,
  "title": "…",
  "summary": "…",
  "pubkey": "<hex>",
  "npub": "npub1…",
  "slug": "my-slug",
  "topics": ["nostr", "bitcoin"],
  "image": "https://… | null",
  "publishedAt": "2024-01-02T03:04:05+00:00 | null",
  "createdAt": "2024-01-02T03:04:05+00:00 | null",
  "url": "https://…/p/npub1…/d/my-slug | null",
  "content": "full markdown (only on /get)"
}
```

Drafts (kind 30024) are excluded; results are deduplicated by coordinate keeping
the newest revision.

## MCP tools

Article tools are read-only, thin passthroughs to the token-guarded internal API.

| Tool | Arguments | Description |
|------|-----------|-------------|
| `search_articles` | `query`, `limit`, `offset` | Full-text search (metadata only). |
| `get_article` | `coordinate` | One article with full content. |
| `list_latest` | `limit` | Most recent articles. |
| `list_by_author` | `author` (hex/npub), `limit`, `offset` | Articles by an author. |
| `list_by_topic` | `topics[]`, `limit`, `offset` | Articles tagged with any topic. |
| `list_topics` | `topics[]` | Article counts per topic. |

Resource template: `dn://article/{coordinate}` returns a single article (full
content) so clients can attach it as context by URI.

## Books API contract

The MCP service also consumes the newsroom's read-only Books API at
`/books/api`. It reads the configured external Elasticsearch alias and has no
database or relay fallback.

| Tool | Arguments | Description |
|------|-----------|-------------|
| `search_books` | `query`, `title`, `author`, `language`, `subject`, `d`, `identifier`, `limit` | Searches kind 30040 publication indexes. All search fields are optional. |
| `get_book` | `eventId` | Retrieves a kind 30040 publication index by its exact Nostr event ID. |
| `search_book_sections` | `query`, `limit` | Full-text searches kind 30041 publication sections. |

Resource template: `dn://book/{eventId}` returns a kind 30040 publication
index, allowing clients to attach a book's Nostr event as context. See
[`documentation/API/books-api.md`](../API/books-api.md) for the canonical Books
API contract.

## Configuration (`mcp` service)

The public newsroom footer links to `/mcp`, which displays the configured MCP
endpoint and a client configuration example.

| Env | Purpose |
|-----|---------|
| `NEWSROOM_INTERNAL_API_BASE` | Base URL of the newsroom internal API (e.g. `http://php`). |
| `INTERNAL_API_TOKEN` | Shared secret; must match the newsroom app. |
| `MCP_HTTP_HOST` / `MCP_HTTP_PORT` | HTTP transport bind address/port. |

See `docker/mcp/.env.example`. On the newsroom side, set the matching `INTERNAL_API_TOKEN`
(already wired into `config/services.yaml` as `internal_api.token`).

## Running

### Docker (streamable HTTP)

```bash
# From the repo root — opt-in profile.
docker compose --profile mcp up -d --build mcp
```

The service listens on `MCP_HTTP_PORT` (default 9000) at `/mcp`. In production,
Caddy proxies the public MCP subdomain to the service.

### stdio (local desktop clients)

Build the image once, then have the client launch the binary:

```json
{
  "mcpServers": {
    "decent-newsroom": {
      "command": "docker",
      "args": [
        "compose", "-f", "/abs/path/to/newsroom/compose.yaml",
        "run", "--rm", "-T", "mcp", "php", "bin/mcp-stdio"
      ],
      "env": {
        "NEWSROOM_INTERNAL_API_BASE": "http://php",
        "INTERNAL_API_TOKEN": "…"
      }
    }
  }
}
```

Or run it directly inside the container for testing:

```bash
docker compose run --rm -T mcp php bin/mcp-stdio
```

### HTTP client config

```json
{
  "mcpServers": {
    "decent-newsroom": {
      "url": "https://mcp.example.com/mcp"
    }
  }
}
```

## Development & tests

The `docker/mcp/` project has its own toolchain (no relation to the newsroom app's):

```bash
cd docker/mcp
composer install
vendor/bin/phpunit
php bin/mcp-stdio           # boots the server; discovers 9 tools + 2 templates
```

Newsroom-side tests for the internal contract:

```bash
docker compose exec php bin/phpunit tests/Unit/Service/Internal
```

## First deployment to production (checklist)

Prod runs `compose.yaml` + `compose.prod.yaml` with `--env-file .env.prod.local`.
The article internal API is part of the **newsroom app image**, so the `php` image
must be rebuilt; the `mcp` service is a **separate image** built from `docker/mcp`.

There is **no database migration** — the internal API reuses existing tables.

### 1. Generate and set secrets in `.env.prod.local`

```bash
# Shared secret used only for MCP-to-newsroom article API requests.
php -r "echo 'INTERNAL_API_TOKEN='.bin2hex(random_bytes(32)).PHP_EOL;"
```

Append to `.env.prod.local`:

```dotenv
# MCP service and newsroom app
INTERNAL_API_TOKEN=<generated>       # shared by the php app AND the mcp service
NEWSROOM_INTERNAL_API_BASE=http://php
# Do NOT publish port 9000 in prod — Caddy fronts it.
```

The `php` prod service loads `.env.prod.local` via `env_file`, so it picks up
`INTERNAL_API_TOKEN` automatically. The `mcp` service reads these via compose
interpolation from `--env-file .env.prod.local`.

### 2. Enable the `mcp` service in prod (no public port)

This is **already wired** in `compose.prod.yaml` (the `###> mcp server ###`
override): `profiles: !reset []` auto-enables it in prod (like relay-gateway /
essayist), and `ports: !reset []` drops the published host port so it is only
reachable as `mcp:9000` on the internal network. Nothing to do here beyond
setting the secrets in step 1.

### 3. Route the public MCP endpoint through Caddy (reuse the `chat` subdomain)

The Caddy route is **already applied** in `frankenphp/Caddyfile` (the
`@mcpHost` / `@mcpHostForwarded` handlers): requests to the `chat.` host are
intercepted before the catch-all and proxied to `mcp:9000` without client
authentication.

We reuse the **existing, idle `chat.` subdomain** instead of standing up a new
`mcp.` one — its DNS record and TLS cert are already in place, so there is
**nothing new to provision**. Just set the domain in `.env.prod.local`:

```dotenv
MCP_DOMAIN=chat.decentnewsroom.com
```

If you front everything with an **external** proxy
(nginx/Cloudflare/Traefik), forward `chat.decentnewsroom.com` to the container.

> Hygiene note: the `chat.` name no longer describes what it serves. Fine for now
> since it's idle, but consider renaming to a dedicated `mcp.` subdomain later —
> only the `MCP_DOMAIN` value and a DNS record change; no code impact.

### 4. Build and deploy

```bash
# Rebuild the app image (ships the internal API) and the mcp image, then bring up.
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  build php mcp

docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local \
  up -d
```

### 5. Verify

```bash
# a) Internal API rejects unauthenticated calls (expect 401):
docker compose exec php sh -lc 'curl -s -o /dev/null -w "%{http_code}\n" \
  http://php/internal/api/articles/latest'

# b) Internal API works with the token (expect 200 + JSON):
docker compose exec php sh -lc 'curl -s -H "X-Internal-Token: $INTERNAL_API_TOKEN" \
  "http://php/internal/api/articles/latest?limit=1"'

# c) mcp reaches the app and discovers tools (expect: tools=9 templates=2):
docker compose exec mcp php -r 'require "vendor/autoload.php";
  $s=DecentNewsroom\Mcp\ServerFactory::build();
  echo "tools=".count($s->getRegistry()->getTools())." templates=".count($s->getRegistry()->getResourceTemplates()).PHP_EOL;'

# d) Public endpoint is reachable without client credentials:
curl -s -o /dev/null -w "%{http_code}\n" https://chat.your-domain.com/mcp
```

### 6. Register a client

Give the client the public URL:

```json
{
  "mcpServers": {
    "decent-newsroom": {
      "url": "https://chat.your-domain.com/mcp"
    }
  }
}
```

### Rollback / disable

Remove the `mcp` override (or set `profiles: [mcp]` again) and redeploy, or just
`docker compose ... stop mcp`. The internal API stays dormant with no external
surface (it is only reachable on the internal network and requires the token).

### Don't forget

- [ ] `INTERNAL_API_TOKEN` is a random secret shared only by the MCP service and newsroom app.
- [ ] `.env.prod.local` is never committed.
- [ ] Rebuilt the **php** image (internal API is app code), not just `mcp`.
- [ ] Port 9000 is **not** published publicly (`ports: !reset []`).
- [ ] The public `chat.` MCP endpoint has no client-authentication requirement.
- [ ] `MCP_DOMAIN=chat.your-domain.com` set; existing chat DNS/TLS reused (no new record needed).
- [ ] No DB migration required — do not run one for this feature.

## Design notes

- **Only coupling is the JSON contract** of `/internal/api/articles` and
  `/books/api`. Keep both response shapes additive.
- **Limits, dedup and draft exclusion** live in the newsroom internal API (single
  source of truth); the MCP tools stay dumb passthroughs.
- **No writes.** The MCP surface is strictly read-only; there is no publishing or
  arbitrary event-id fetch path.
