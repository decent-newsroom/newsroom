# Chat Setup Guide

How to set up a community chat instance from scratch after updating Docker.

## Prerequisites

- Docker services running (`docker compose up -d`)
- A main-app admin account (your npub has `ROLE_ADMIN`)
- `APP_ENCRYPTION_KEY` set in your `.env.local` (32-byte hex string for AES-256-GCM)

Generate an encryption key if you don't have one:

```bash
docker compose exec php php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Add it to `.env.local`:

```dotenv
APP_ENCRYPTION_KEY=<the-hex-string>
```

## 1. Run the migration

```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

This creates the 7 chat tables and adds self-sovereign admin support columns.

## 2. Subdomain routing

Caddy is already configured with `SERVER_NAME=localhost,*.localhost` (dev) or `yourdomain.com, *.yourdomain.com` (prod), so all subdomains are routed to FrankenPHP automatically.

`ChatRequestListener` extracts the subdomain from the host header using `BASE_DOMAIN` and matches it against registered `ChatCommunity` entries. No hosts file or DNS changes needed for local dev — `*.localhost` resolves to `127.0.0.1` by default.

### Production

For production, ensure:

```dotenv
# .env.prod.local
SERVER_NAME=yourdomain.com, *.yourdomain.com
BASE_DOMAIN=yourdomain.com
SESSION_COOKIE_DOMAIN=.yourdomain.com
```

- Wildcard DNS (`*.yourdomain.com`) must point to your server.
- `SESSION_COOKIE_DOMAIN` enables cross-subdomain session sharing so self-sovereign admins can authenticate on chat subdomains using their main-app login.

If behind a reverse proxy:

```dotenv
SERVER_NAME=:80
BASE_DOMAIN=yourdomain.com
SESSION_COOKIE_DOMAIN=.yourdomain.com
```

The proxy must forward all subdomains to the container.

## 3. Create a community

Go to the admin panel:

```
https://localhost:8443/admin/chat
```

Click through to **Communities → New**:
- **Subdomain**: `oakclub` (this becomes `oakclub.localhost` in dev)
- **Name**: `Oak Club` (display name)
- **Relay URL**: leave empty (defaults to internal `ws://strfry-chat:7778`)

## 4. Create the first user (custodial system user)

Go to **Communities → Oak Club → Users → Create User**:
- Leave the **npub** field empty
- **Display Name**: `System` (or anything — this is used to sign channel creation events)
- **Role**: `Admin`

This creates a custodial user with server-managed keys. You need at least one custodial user to sign kind-40 channel creation events from the admin panel.

## 5. Add yourself as a self-sovereign admin

Still on the Users page, create another user:
- **npub**: paste your `npub1...` (must match an npub that has logged into the main app at least once)
- **Display Name**: ignored (pulled from your main-app profile)
- **Role**: `Admin`

This links your main-app identity to the chat community. When you visit `oakclub.localhost`, you'll be automatically authenticated via your main-app session.

## 6. Create a group (channel)

Go to **Communities → Oak Club → Groups → Create**:
- **Name**: `General`
- **Slug**: `general`

This publishes a NIP-28 kind-40 channel creation event to the chat relay (signed by the custodial system user from step 4).

## 7. Add members to the group

Go to **Groups → General → Members** and add your users (both the system user and your self-sovereign admin) to the group.

## 8. Visit the chat

Open your chat subdomain:

```
https://oakclub.localhost:8443/groups
```

If you're logged into the main app, you'll be authenticated automatically (self-sovereign admin). Click into the `General` group and start chatting.

Your messages are signed client-side via your NIP-07 browser extension (or NIP-46 remote signer) — the server validates and publishes them to the chat relay.

## 9. Invite regular users

Go to **Communities → Oak Club → Invites → Generate**:
- **Role**: `User`
- **Max uses**: leave empty for unlimited, or set a number

Copy the generated invite URL (e.g., `https://oakclub.localhost:8443/activate/abc123...`).

When someone opens this link:
1. A custodial chat account is created for them (server-generated keys)
2. They're activated and given a session cookie
3. They're redirected to `/groups`

Their messages are signed server-side — they don't need a browser extension.

## Architecture Summary

```
Self-sovereign admin (you):
  Browser → NIP-07 sign → POST /groups/general/messages/signed → validate → relay → Mercure

Custodial user (invited):
  Browser → POST /groups/general/messages → server signs kind 42 → relay → Mercure

Both users see the same messages via Mercure SSE in real time.
All messages stored on strfry-chat relay only (no DB).
```

## Troubleshooting

### "No main-app user found with that npub"
The npub you entered hasn't logged into the main app yet. They need to log in at least once to create their `User` entity.

### Self-sovereign admin sees "Invite Required"
- Check that `SESSION_COOKIE_DOMAIN` is set (production). In dev, `*.localhost` cookies are shared by default.
- Make sure you're logged into the main app first.
- Verify the `ChatUser` exists and is `active` for that community.

### Group creation fails with "Create at least one user"
You need at least one custodial user in the community. The admin panel uses a custodial user's keys to sign the kind-40 channel creation event.

### strfry-chat not starting
Check: `docker compose logs strfry-chat`. Verify `docker/strfry-chat/strfry.conf` exists and port 7778 is configured.

### Chat subdomain returns 502 or main site
- Verify `SERVER_NAME` includes `*.localhost` (dev) or `*.yourdomain.com` (prod).
- Verify the community's subdomain matches exactly what you registered in the admin panel.
- Check `docker compose logs php` for `ChatRequestListener` errors.

