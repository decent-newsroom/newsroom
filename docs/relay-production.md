# Configuring Relay Subdomain in Production

This guide explains how to set up `relay.decentnewsroom.com` in production.

## Prerequisites

1. DNS A record for `relay.decentnewsroom.com` pointing to your server's IP address
2. DNS A record for your main domain (e.g., `decentnewsroom.com`) pointing to your server's IP address

## Configuration Steps

### 1. Set Environment Variables

On your production server, create or update your `.env.local` or `.env.prod.local` file with:

```bash
# Main domain
SERVER_NAME=decentnewsroom.com

# Relay subdomain
RELAY_DOMAIN=relay.decentnewsroom.com

# Internal relay connection (used by Symfony app)
NOSTR_DEFAULT_RELAY=ws://strfry:7777
```

### 2. Configure Trusted Hosts (Optional but Recommended)

Update your environment to trust both domains:

```bash
TRUSTED_HOSTS='^(.+\.)?decentnewsroom\.com$'
```

### 3. Deploy

The strfry relay runs in its own compose project (`compose.strfry.yaml`),
separate from the main application services.

Start or restart the relay:

```bash
# Start strfry (creates the shared "newsroom" network)
docker compose -f compose.strfry.yaml --env-file .env.prod.local up -d

# Start/restart the app (if not already running)
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d
```

> See [Strfry Separation](../documentation/Deployment/strfry-separation.md) for
> the full deployment architecture and migration guide.

## How It Works

1. **Caddy Configuration**: The `frankenphp/Caddyfile` has a block that reads the `RELAY_DOMAIN` environment variable:
   ```caddyfile
   {$RELAY_DOMAIN:relay.localhost} {
       log {
           format json
       }
       encode zstd gzip
       reverse_proxy strfry:7777
   }
   ```

2. **Automatic TLS**: When you set `RELAY_DOMAIN=relay.decentnewsroom.com`, Caddy will:
   - Listen on that domain
   - Automatically obtain a Let's Encrypt TLS certificate
   - Proxy WebSocket connections to the strfry relay service on port 7777

3. **Main App**: Your main Symfony application runs on `decentnewsroom.com`

4. **Relay Service**: The Nostr relay is accessible at `wss://relay.decentnewsroom.com`

## Testing

After deployment, you can test the relay:

1. **Check the relay info**:
   ```bash
   curl https://relay.decentnewsroom.com
   ```

2. **Test WebSocket connection** using a Nostr client or tool like `websocat`:
   ```bash
   websocat wss://relay.decentnewsroom.com
   ```

## Troubleshooting

### Certificate Issues
If you encounter certificate issues, check:
- DNS records are properly configured and propagated
- Firewall allows ports 80 and 443
- Check Caddy logs: `docker compose -f compose.yaml -f compose.prod.yaml logs php`

### Relay Not Responding
If the relay doesn't respond:
- Check strfry is running: `docker compose -f compose.strfry.yaml ps`
- Check strfry logs: `docker compose -f compose.strfry.yaml logs strfry`
- Verify internal connectivity: `docker compose exec php curl http://strfry:7777`
- Verify both projects share the network: `docker network inspect newsroom`

## Port Mapping in Production

Note: Unlike the development setup (`compose.yaml`), the production configuration does NOT expose port 7777 directly. All relay traffic should go through Caddy on port 443 (HTTPS/WSS).

