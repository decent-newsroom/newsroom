# mcp

Standalone [Model Context Protocol](https://modelcontextprotocol.io) server that
exposes the Decent Newsroom article corpus **read-only** to AI clients (Claude
Desktop, Cursor, etc.). Independent of the newsroom app image — it reads data
only through the token-guarded internal API (`http://php/internal/api/articles`).
See [`documentation/MCP/mcp-server.md`](../../documentation/MCP/mcp-server.md) for
the full design, internal API contract, and client config.

## Layout

```
bin/mcp-stdio                stdio transport entrypoint (local desktop clients)
bin/mcp-http                 streamable HTTP transport entrypoint (remote clients)
src/ServerFactory.php        builds + wires the php-mcp/server Server
src/ArrayContainer.php       minimal PSR-11 container for element DI
src/Client/NewsroomApiClient.php   typed wrapper over the newsroom internal API
src/Tool/ArticleTools.php    6 #[McpTool] read-only tools
src/Resource/ArticleResources.php  dn://article/{coordinate} resource template
tests/Unit/                  client + tools unit tests
```

## Tools

`search_articles`, `get_article` (by `kind:pubkey:slug`), `list_latest`,
`list_by_author` (hex or npub), `list_by_topic`, `list_topics`, plus the
`dn://article/{coordinate}` resource template. All read-only; limits, dedup and
draft exclusion are enforced server-side by the newsroom internal API.

## Local build

```sh
cd docker/mcp
composer install
vendor/bin/phpunit           # 9 unit tests
php bin/mcp-stdio            # boots the server; discovers 6 tools + 1 template
```

`bin/mcp-stdio` loads a local `.env` if present (copy from `.env.example`).
Never write to STDOUT from handlers — it is reserved for JSON-RPC.

## Docker

```sh
# Streamable HTTP transport (opt-in profile), listens on MCP_HTTP_PORT (default 9000).
docker compose --profile mcp build mcp
docker compose --profile mcp up -d mcp

# stdio, for a locally-launched desktop client:
docker compose run --rm -T mcp php bin/mcp-stdio
```

## Required env

```
NEWSROOM_INTERNAL_API_BASE   # base URL of the newsroom internal API, e.g. http://php
INTERNAL_API_TOKEN           # X-Internal-Token shared secret; must match the PHP app
MCP_AUTH_TOKEN               # bearer token required from HTTP clients (see auth note)
MCP_HTTP_HOST / MCP_HTTP_PORT  # HTTP transport bind address/port (default 0.0.0.0:9000)
```

## Auth

Two independent layers:

- **MCP client → this service (HTTP)**: `Authorization: Bearer $MCP_AUTH_TOKEN`,
  enforced at the reverse proxy (Caddy) in front of the service — the SDK's HTTP
  transport has no built-in auth hook, so **do not expose this port directly** to
  the public internet.
- **This service → newsroom app**: `X-Internal-Token: $INTERNAL_API_TOKEN`,
  enforced by `App\EventSubscriber\InternalApiTokenSubscriber` (fails closed).

The stdio transport has no network surface (the client launches the binary
locally), so only `INTERNAL_API_TOKEN` applies there.
