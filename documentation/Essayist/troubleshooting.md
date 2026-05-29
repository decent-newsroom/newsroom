# Essayist Relay Troubleshooting

## 404 Error When Accessing the Relay

### Symptoms
- `https://essayist.decentnewsroom.com` returns HTTP 404
- Gateway may not be responding or Docker service is down

### Root Causes & Fixes

#### 1. **Essayist Profile Not Activated (Most Common)**

The `essayist-gateway` and `strfry-essayist` services are in the `essayist` Docker Compose profile. They must be explicitly activated.

**Check status:**
```bash
docker compose ps | grep essayist
```

If no output, the services aren't running.

**Fix for development:**
```bash
docker compose --profile essayist up -d
```

**Fix for production:**
Ensure `compose.prod.yaml` has the essayist profile enabled (it now does via `profiles: !reset []`). When deploying, use:
```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d
```

#### 2. **Missing or Incorrect Environment Variables**

The gateway requires these environment variables:

| Variable | Purpose | Example |
|----------|---------|---------|
| `ESSAYIST_RELAY_PUBLIC_URL` | Public relay URL (must match Host header exactly) | `wss://essayist.decentnewsroom.com` |
| `ESSAYIST_RELAY_DOMAIN` | Caddy routing: exact domain to match | `essayist.decentnewsroom.com` |
| `ESSAYIST_POLICY_TOKEN` | Bearer token for membership checks | `<random-secret>` |
| `REDIS_HOST` | Redis hostname (same as other services) | `redis` (compose) or `redis.example.com` (prod) |
| `REDIS_PORT` | Redis port (default 6379) | `6379` |
| `REDIS_PASSWORD` | Redis password (same as other services) | Set in `.env.prod.local` |

⚠️ **Common mistake:** Accessing `essayis.decentnewsroom.com` (typo without the **t**) when your config is set to `essayist.decentnewsroom.com`. The domain must match **exactly**.

**Check what's set:**
```bash
docker compose config | grep -i essayist
```

**In production**, add to `.env.prod.local`:
```bash
ESSAYIST_RELAY_PUBLIC_URL=wss://essayist.decentnewsroom.com
ESSAYIST_RELAY_DOMAIN=essayist.decentnewsroom.com
ESSAYIST_POLICY_TOKEN=<generate-a-random-secret>
REDIS_HOST=redis.yourmanagedservice.com  # or 'redis' if local
REDIS_PORT=6379
REDIS_PASSWORD=<your-redis-password>
```

#### 3. **Reverse Proxy Rewriting Host Header**

When running behind a reverse proxy (nginx, HAProxy, etc.), the proxy may rewrite the `Host` header to something different than what the browser sent. This causes Caddy's matcher to fail.

**Symptoms:**
- Browser sends correct domain (`essayist.decentnewsroom.com`)
- But Caddy logs show a different Host header (`essayis.decentnewsroom.com` or similar)
- The request never matches your matcher and returns 404

**Check the logs:**
```bash
docker compose logs php | grep "essayist" | head -5
```

Look for mismatch between:
- `"Referer": ["https://essayist.decentnewsroom.com/"]` (correct, from browser)
- `"host":"essayis.decentnewsroom.com"` (wrong, rewritten by proxy)

**Solution:**
The Caddyfile now matches on BOTH:
- Direct `Host` header (direct connections)
- `X-Forwarded-Host` header (reverse proxy connections)

This is already configured in `frankenphp/Caddyfile`. If your proxy still doesn't work, verify:

1. **Your reverse proxy is sending `X-Forwarded-Host` correctly:**
   ```bash
   # Test from outside the container
   curl -i -H "X-Forwarded-Host: essayist.decentnewsroom.com" \
     https://your-server/
   ```

2. **If it's nginx**, ensure your upstream config includes:
   ```nginx
   proxy_set_header Host $http_host;
   proxy_set_header X-Forwarded-Host $http_host;
   proxy_set_header X-Real-IP $remote_addr;
   proxy_set_header X-Forwarded-Proto $scheme;
   ```

3. **If it's a cloud load balancer**, check that it's not hardcoding Host headers.

---

#### 4. **NIP-11 / Browser HTTP behavior**

The gateway now proxies any unauthenticated `GET` request to strfry and returns
strfry's native HTTP response (status/body/content-type). This means browser
requests to the relay URL no longer fail solely because of `Accept: text/html`.

To test both paths:
```bash
# NIP-11 metadata response
curl -H "Accept: application/nostr+json" https://essayist.decentnewsroom.com/

# Browser-like Accept header (should now be proxied to strfry as-is)
curl https://essayist.decentnewsroom.com/
```

If this still returns `404`, check host/domain matcher logs and reverse-proxy
header forwarding (`Host` / `X-Forwarded-Host`).

#### 5. **Gateway Container Health Check Failing**

The gateway performs health checks by dialing the upstream relay and Redis.

**View gateway logs:**
```bash
docker compose logs essayist-gateway --tail 100
```

**Check gateway health endpoint:**
```bash
docker compose exec essayist-gateway wget -qO- http://localhost:7781/health
```

Should return JSON like:
```json
{"upstream":"ok","redis":"ok"}
```

If Redis or upstream is failing, you'll see:
```json
{"upstream":"fail: connection refused","redis":"ok"}
```

**Solutions:**
- If `upstream` fails: Ensure `strfry-essayist:7779` is reachable and healthy
- If `redis` fails: Check Redis connectivity from the gateway container

#### 6. **Strfry Essayist Not Starting**

**Check if the relay is up:**
```bash
docker compose logs strfry-essayist --tail 50
```

**Common issues:**
- Port 7779 already in use (on Windows with WSL2): Change port in `compose.yaml`
- Write policy script not executable: The compose command fixes this automatically
- Data directory permissions: Ensure `./docker/strfry-essayist/` and volumes have correct permissions

---

## Testing the Relay Manually

### 1. Test WebSocket Connection (as an authenticated member)

```javascript
// In a Nostr client or console, with NIP-07 support:
const authEvent = await window.nostr.signEvent({
  kind: 22242,
  created_at: Math.floor(Date.now() / 1000),
  tags: [
    ["challenge", "..."],  // Sent by gateway
    ["relay", "wss://essayist.decentnewsroom.com"]
  ],
  content: ""
});
// Connect WebSocket and send AUTH
```

### 2. Test NIP-11 Metadata Endpoint

```bash
curl -i \
  -H "Accept: application/nostr+json" \
  https://essayist.decentnewsroom.com/
```

Should return:
```json
{
  "name": "Essayist",
  "description": "Members-only relay for longform writing",
  "auth_required": true,
  "payment_required": false,
  "supported_nips": [1, 9, 11, 42, 50],
  "fees": { "subscription": [...] },
  "relay_url": "wss://essayist.decentnewsroom.com"
}
```

### 3. Check Gateway Metrics

```bash
docker compose exec essayist-gateway wget -qO- http://localhost:7782/metrics | head -30
```

Look for:
- `gateway_auth_total` — authentication outcomes (successes, rejections, timeouts)
- `gateway_membership_cache_total` — Redis cache hits/misses
- `gateway_active_connections` — current WebSocket connections

---

## Production Deployment Checklist

- [ ] `ESSAYIST_RELAY_PUBLIC_URL` set and matches your DNS A record
- [ ] `ESSAYIST_RELAY_DOMAIN` set to the subdomain (e.g., `essayist.decentnewsroom.com`)
- [ ] `ESSAYIST_POLICY_TOKEN` set to a strong random secret
- [ ] `ESSAYIST_GATEWAY_REDIS_URL` points to external Redis (if using managed Redis)
- [ ] DNS A record created for `essayist.decentnewsroom.com`
- [ ] `compose.prod.yaml` is used in the startup command
- [ ] Essayist services are in the startup logs: `docker compose logs | grep essayist`
- [ ] Gateway health check passes: `docker compose exec essayist-gateway wget -qO- http://localhost:7781/health`
- [ ] NIP-11 endpoint responds: `curl -H "Accept: application/nostr+json" https://essayist.decentnewsroom.com/`
- [ ] Relay is visible in Nostr clients that support Essayist

