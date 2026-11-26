# PHP runtime security updates

The PHP security release of 21 October 2026 fixes CVE-2026-109618 and
CVE-2026-109619, which can allow unauthenticated remote code execution.
The [official advisory](https://www.php.net/archive/2026.php#2026-10-21-5)
explicitly includes FrankenPHP among affected web-serving installations.

## Runtime policy

- The application stays on PHP 8.5. Its Dockerfile rejects versions below
  **8.5.12** and rejects other minor versions before installing dependencies.
- The independent MCP image stays on PHP 8.3, pinned to **8.3.37** by a
  multi-platform image digest, with its own build-time version check.
- Composer's PHP constraint is an application compatibility baseline, not proof
  that a runtime has received security patches.

The FrankenPHP `1.14.0-php8.5` multi-platform image is pinned by digest and
contains PHP 8.5.13. Existing running containers are not updated by changing
the repository; rebuild and recreate them as described below.

FrankenPHP 1.14 bundles Mercure 1.x. The Caddy configuration explicitly enables
Mercure 0.8 compatibility and retains the `mercureAuthorization` cookie so the
existing topic-based subscribers and JWT claims continue to work. A future
Mercure 1.x client migration must update publishers, subscribers and tokens
together before removing compatibility mode. The shared hub is named `newsroom`
to allow the same site block to serve both the public and internal addresses.
The development override uses Mercure's `playground` directive in place of
the removed `demo` directive; do not enable the playground in production.

When updating FrankenPHP, verify its actual PHP version and pin the verified tag
and multi-platform digest. Keep PHP 8.5 and the Debian variant: this Dockerfile uses
`apt-get`, Bash and cron. Do not copy a PHP CLI binary into an old FrankenPHP image;
the embedded web runtime must also be patched.

The historical `docker/cron/Dockerfile` is not used by current Compose services
and still targets PHP 8.2. Do not use it for this rollout. Current cron and all
application workers share the root application's PHP image.

## Rebuild and deploy

`session.upload_progress.enabled = off` is set in the shared
`frankenphp/conf.d/10-app.ini`, inherited by development, production, cron and
application workers. The separate MCP image loads the same setting from
`docker/mcp/conf.d/10-security.ini`. Neither environment-specific application
INI file overrides it. This disables session upload-progress tracking, not file
uploads, and applies before request parsing rather than through `ini_set()`.
It mitigates confirmed session-related paths for CVE-2026-109619 but does not
replace the PHP update. Recreate affected containers after deploying the
configuration and verify that `ini_get('session.upload_progress.enabled')`
returns an empty string or `0` in each PHP service.

Use the same production environment file, Compose project name, secrets and
profiles as the existing deployment for every command below. The examples assume
the production environment is already loaded. Build both PHP images before
recreating any services; do not proceed if either build fails.

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull php mcp
docker compose -f compose.yaml -f compose.prod.yaml run --rm --no-deps --entrypoint php php -v
docker compose -f compose.yaml -f compose.prod.yaml run --rm --no-deps --entrypoint composer php check-platform-reqs --no-dev
docker compose -f compose.yaml -f compose.prod.yaml run --rm --no-deps --entrypoint php mcp -v
docker compose -f compose.yaml -f compose.prod.yaml up -d --no-deps --force-recreate --wait php mcp cron worker worker-relay worker-profiles
```

If the relay gateway is deployed, also recreate it using the deployment's gateway
profile. Keep existing database, Redis and relay services running; this update
does not require replacing them.

```console
docker compose -f compose.yaml -f compose.prod.yaml --profile gateway up -d --no-deps --force-recreate --wait relay-gateway
```

Verify `php -v` inside **each** running PHP service after deployment, including
the gateway when enabled. Record the resulting image digests. Confirm web requests,
authentication, uploads, cron and background processing work normally. Run the
existing PHPUnit suite inside a rebuilt development container before rollout.

An ordinary container restart continues using its old image. `build --pull`
refreshes referenced bases, but a digest pin must first be updated explicitly.
Never remove or lower the version guards to make a failing security build pass.
