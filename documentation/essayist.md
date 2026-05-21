# Essayist

## Working Definition

**Essayist is a Nostr relay for longform writing where access works by contributing to the existing membership.**

To join, you send a small amount of sats to someone in the membership. That grants you access for the next month. You can read. You can post. No approval required. No content gating. If you irritate people, they mute you, and you lose your audience. That is the only enforcement.

---

# 1. The Simple Explanation

## One-Line Pitch

**Essayist is a relay where your entry fee goes directly to the people already contributing.**

## Member Explanation

To access Essayist, you make a small contribution to someone in the current membership. This renews monthly. While your membership is active, you can publish longform articles to the relay and read everything on it. If someone is disruptive, other members mute them. The relay operator does not need to police content.

---

# 2. Core Principle

**The membership supports itself.**

Money flows between members, not to a platform. Everyone who participates is both a potential recipient and a contributor. The relay operator facilitates access and keeps the relay running; they do not act as editorial gatekeeper.

---

# 3. Product Scope

## Required

* Essayist landing page explaining the membership model. ✓ Built
* Nostr login. ✓ Already existed
* Contribution flow: logged-in user sends sats to someone in the membership, access is verified and granted.
* Manual verification fallback (admin grants `ROLE_ESSAYIST_MEMBER` via CLI or admin panel). ✓ Built
* `ROLE_ESSAYIST_MEMBER` granted once contribution is verified, valid through end of next month.
* Essayist feed page gated by `ROLE_ESSAYIST_MEMBER`.
* Relay write access gated by `ROLE_ESSAYIST_MEMBER`. ✓ Built
* Admin view: pending requests + active members + grant/revoke. ✓ Built
* Editor: "Publish to Essayist" option (member-only checkbox to add Essayist relay to the publish target list).
* Editor: "Publish ONLY to Essayist" option (member-only; restricts publish to Essayist relay only, adds NIP-70 `-` tag).
* Article actions: "Broadcast to Essayist" (replay signed event to Essayist relay, member-only, author-only).
* Admin preview access to all role-gated Essayist pages and flows without requiring `ROLE_ESSAYIST_MEMBER`.

## Optional but Useful

* Public member count. ✓ Shown on landing page
* Role expiry cron (removes lapsed membership automatically).
* Landing page showing recent public articles from the relay.

## Defer

* Algorithmic sat allocation.
* Cashu mint.
* Complex ledger.
* Automatic cartel detection.
* Many access tiers.
* Public audit system.

---

# 4. Access Model

## One Role: `ROLE_ESSAYIST_MEMBER`

There is a single access role. It grants:

- read access to the gated DN feed page (`/essayist/feed`);
- write access to the Essayist relay (`wss://essayist.decentnewsroom.com`).

Both reading and writing require active membership. The relay is not intended for public access.

## Contribution

The contribution is a small fixed amount of sats (suggested 1 000–5 000 sats/month). It goes to a current active member chosen via the landing page interface. The split is even or proportional — operator decides.

Mechanics:

1. Visitor logs in with their Nostr key.
2. They see the current member count, the required contribution, and a payment interface.
3. They pay. Manual verification for now — admin runs `user:elevate <npub> ROLE_ESSAYIST_MEMBER`.
4. Access is active for the current month and the next.
5. At expiry, access lapses. Renew by contributing again.

## Content Enforcement

None. The relay accepts all kind 30023 from any member pubkey. No editorial review. No quality gate. If a member publishes content that others dislike, the community mutes them. The relay operator reserves the right to delete content for legal reasons.

---

# 5. Early Bird (pre-launch offer)

The relay goes live **1 June 2026**. Until **1 June 2026**, logged-in users can claim free membership for June with a single click on the landing page. This assigns both `ROLE_ESSAYIST_EARLY_BIRD` and `ROLE_ESSAYIST_MEMBER`. No payment required for the early-bird period.

After the deadline, the claim button disappears. The early-bird section remains visible.

---

# 6. Public Announcement Copy

## Option A

Launching Essayist: a Nostr relay for longform writing where your monthly contribution goes directly to the existing membership.

No writer approval. No reader tier. One role: member. Publish, read, stay in. Mute what you do not want to see.

Join at `decentnewsroom.com/essayist`.

## Option B

Essayist is a relay experiment.

The premise: what if the entry fee went directly to the participants instead of the platform? Monthly sats, distributed to current members. One month of full access. Renew to stay in.

No curation. No approval. The relay and its members govern by muting.

`decentnewsroom.com/essayist`

---

# 7. Technical Implementation

---

## Roles

Three roles are in use:

| Role | Purpose |
|---|---|
| `ROLE_ESSAYIST_MEMBER` | Active membership — relay write access + gated feed read. |
| `ROLE_ESSAYIST_EARLY_BIRD` | Supplementary badge for pre-launch free June access. Also holds `ROLE_ESSAYIST_MEMBER`. |
| `ROLE_ESSAYIST_CANDIDATE` | Transitional flag: user has clicked "Request Access" and is awaiting admin approval. Removed on approve (→ `ROLE_ESSAYIST_MEMBER`) or reject. |

The roles `ROLE_ESSAYIST_AUTHOR` and `ROLE_ESSAYIST_SUPPORTER` were part of an earlier writer-approval model and have been **removed** from `RolesEnum` and all admin tooling. No users were ever assigned them.

---

## Controllers

| Controller | Routes | Purpose |
|---|---|---|
| `App\Controller\EssayistController` | `GET /essayist`, `POST /essayist/early-bird`, `POST /essayist/request-access`, `GET /essayist/feed` *(planned)*, `GET /essayist/home` *(planned)* | Public landing page, pre-launch sign-up flows, and gated member pages |
| `App\Controller\Administration\EssayistAdminController` | `/admin/essayist/*` | Admin management of members and candidates |
| `App\Controller\Api\EssayistWriterPolicyController` | `GET /api/internal/essayist/writer/{pubkey}` | Internal bearer-token endpoint called by `write-policy.sh` |

---

## What is built

### Landing page (`/essayist`) ✓

Publicly accessible. Sections:
- Hero with state-aware CTA (member / logged-in pre-launch / logged-in post-launch / anon)
- Countdown to launch / member count after launch
- Early-bird offer (visible until 31 May 2026)
- How it works
- Join section — submit button is **date-conditional**: disabled + "opens on" hint before launch; enabled form post-launch; anon gets a login link post-launch
- Model explanation
- FAQ

### Relay write-policy ✓

`GET /api/internal/essayist/writer/{pubkey}` checks `ROLE_ESSAYIST_MEMBER` and returns `{"approved": true/false}`. Protected by bearer token (`ESSAYIST_POLICY_TOKEN`). Called by `write-policy.sh` on every incoming `EVENT`. Only kind 30023 passes the kind filter.

> **Note on reads:** strfry's `write-policy.sh` plugin only intercepts `EVENT` messages — it has no hook into `REQ` (subscription/read) messages. Write gating is enforced at the relay protocol level. Read gating is enforced at the app layer (`/essayist/feed` is role-gated) and by keeping the relay WSS URL out of public listings. Full NIP-42 read-level enforcement at the relay protocol layer is handled by `essayist-gateway` (see `documentation/essayist-gateway.md`).

### Admin panel (`/admin/essayist`) ✓

Two sections:
1. **Active Members** — lists `ROLE_ESSAYIST_MEMBER` holders; shows Early Bird badge; grant-by-npub form; per-user revoke.
2. **Pending Candidates** — lists `ROLE_ESSAYIST_CANDIDATE` holders with article count on DN; approve (→ member) or reject.

### Gated feed page (`/essayist/feed`) ✓

`GET /essayist/feed` in `EssayistController`. Accessible to `ROLE_ESSAYIST_MEMBER` and `ROLE_ADMIN` (admin preview with banner). Queries `strfry-essayist:7779` via `EssayistFeedService`, renders with `CardList`. Author metadata resolved from Redis. Internal relay URL: `ESSAYIST_RELAY_INTERNAL_URL` env var.

### Zap infrastructure ✓

`ZapButton.php` (Twig Live Component) handles NIP-57 invoice creation with single and split-payment support. Zap receipts (kind 9735) are co-fetched with comments via `SocialEventService::getComments()` (kinds `[1111, 9735]`), persisted by `CommentEventProjector`, and rendered by `Comments::parseZaps()`.

---

## Open gaps

### 1. Contribution flow UI — not yet built

The member-funded payment flow is not wired into the landing page. Access is granted entirely manually. To implement:
- Show a `ZapButton` on the join section targeting a selected current member's pubkey
- On payment, admin verifies the zap receipt and grants `ROLE_ESSAYIST_MEMBER`
- Future: automate via zap receipt matching (see `SubscriptionZapReceiptWorkerCommand` / `VanityNameZapReceiptWorkerCommand` for the pattern)

### 2. Gated feed page (`/essayist/feed`) — ✓ Built

`GET /essayist/feed` in `EssayistController`. Manual role check: non-members/anons redirect to the landing page; `ROLE_ADMIN` bypasses the gate and sees an admin-preview banner. `EssayistFeedService` connects to `ws://strfry-essayist:7779`, queries kind:30023 until EOSE, and returns stdClass cards compatible with `CardList`. Author metadata resolved from Redis. Internal relay URL configurable via `ESSAYIST_RELAY_INTERNAL_URL` (default `ws://strfry-essayist:7779`).

### 3. Personalized members front page (`/essayist/home`) — not yet built

A role-gated personalized front page, exclusive to `ROLE_ESSAYIST_MEMBER`.

This is the page referenced in the landing page copy ("Members also get a personalized front page based on their favorites, follows and interests"). It functions similarly to the main Decent Newsroom home feed but scoped to the member's own Nostr social graph.

Implementation notes:
- `GET /essayist/home` (or merge `/essayist/feed` into this), guarded by `#[IsGranted('ROLE_ESSAYIST_MEMBER')]`
- Content sources (in priority order):
  1. **Follows** — kind 30023 articles from pubkeys the member follows (kind 3)
  2. **Topic interests** — kind 30023 filtered by hashtags from the member's kind 10015 interest list
  3. **Follow packs** — kind 30023 from pubkeys in any kind 39089 follow packs the member has subscribed to
- Source relays: `strfry-essayist` first (member-published content), then the member's NIP-65 relay list for broader coverage
- Deduplicate by `d`-tag; prefer the most recent version
- Render using the existing home feed tab infrastructure (Turbo Frames + `content--home-tabs` Stimulus controller) so the personalized page can have tabs (e.g. Follows / Topics / All)
- Non-members requesting this route receive a 403 redirect to `/essayist` with a `join_status=login_required` flash

### 4. Role expiry — not yet automated

`ROLE_ESSAYIST_MEMBER` is assigned but never removed automatically. Manage manually for now. Planned: cron command that reads a `membership_granted_at` timestamp and strips the role after 30 days.

### 5. NIP-42 relay-level read enforcement — designed, implementation pending

A dedicated `essayist-gateway` service will sit between Caddy and `strfry-essayist`, enforcing NIP-42 AUTH on every inbound connection before forwarding anything to the relay. This gates both reads (REQ) and writes (EVENT) at the WebSocket protocol layer.

Full design: `documentation/essayist-gateway.md`.

### 6. Editor: "Publish to Essayist" option — not yet built

Adds the Essayist relay (`wss://essayist.decentnewsroom.com`) as an extra publish target in the article editor alongside the user's regular NIP-65 relays.

Visible only to `ROLE_ESSAYIST_MEMBER` users. Implemented as a checkbox in the publish panel of the editor. When checked, the relay URL is appended to the list of relays the signing Stimulus controller sends the EVENT to.

No change to the event itself — the article is published normally and distributed to all selected relays.

### 7. Editor: "Publish ONLY to Essayist" option — not yet built

A separate option that restricts distribution to the Essayist relay exclusively and marks the event as protected.

Behaviour:
- The event is signed with a `-` tag added (NIP-70 "protected event" — signals that the event must not be re-broadcast by any relay that receives it).
- The list of publish relays is replaced with `[wss://essayist.decentnewsroom.com]` only — the user's NIP-65 outbox relays are excluded.
- Visible only to `ROLE_ESSAYIST_MEMBER` users, in the same publish panel as gap #6.

Implementation notes:
- The `-` tag should be included in the event's `tags` array as `["-"]` (NIP-70).
- The signing Stimulus controller needs to respect an "exclusive relay list" mode: when this option is active, the relay list passed to the signer is overridden to contain only the Essayist relay URL.
- Display a clear warning in the UI: "This article will only be visible to Essayist members and will not appear on public feeds."

### 8. Article actions: "Broadcast to Essayist" — ✓ Built

For already-published articles, the article actions dropdown now exposes a
"Broadcast to Essayist" item that re-publishes the stored event to
`wss://essayist.decentnewsroom.com` (configurable via `ESSAYIST_RELAY_PUBLIC_URL`,
default `wss://${ESSAYIST_RELAY_DOMAIN}`).

Visibility: gated to `ROLE_ESSAYIST_MEMBER` or `ROLE_ADMIN` (admins see an
`(admin)` badge for preview purposes — they may broadcast articles even if
they are not active members).

Implementation:
- Twig global `essayist_relay_url` exposes `%essayist.relay_public_url%` to
  templates. `templates/components/Molecules/ArticleActionsDropdown.html.twig`
  renders the menu item only when the URL is non-empty and the viewer holds
  one of the gating roles.
- The button reuses the existing `POST /api/broadcast-article` endpoint with
  an explicit `relays: ["wss://essayist…"]` payload. The original signed event
  is replayed unchanged — no resigning, same `id` and `sig`.
- A dedicated `broadcastEssayist` Stimulus action on
  `ui--article-actions-dropdown` triggers the request and shows a toast such
  as `Broadcast to Essayist: 1/1 relays`.
- `NostrClient::publishEvent` will additionally mirror the event to the local
  strfry relay (its standard behaviour for any explicit relay list) — this is
  a no-op for already-ingested articles.

Caveat: if the article carries an NIP-70 `-` tag, the dropdown hides the
generic "Broadcast" action (existing behaviour) but still allows the
Essayist-only broadcast, since pushing a protected event to the
members-only relay matches its intent.

### 9. Admin preview access — not yet built

Admins (`ROLE_ADMIN`) should be able to access all Essayist-gated pages and flows without holding `ROLE_ESSAYIST_MEMBER`, so they can review and test the member experience.

Scope:
- `/essayist/feed` and `/essayist/home`: replace the bare `#[IsGranted('ROLE_ESSAYIST_MEMBER')]` check with an expression that also permits `ROLE_ADMIN` — e.g. `#[IsGranted('ROLE_ESSAYIST_MEMBER')]` via a Symfony security voter that grants the attribute to any user who is either a member or an admin, or directly via `#[IsGranted('ROLE_ESSAYIST_MEMBER or ROLE_ADMIN')]`.
- The landing page state-aware CTA already reads from `$user->getRoles()`; add an `isAdmin` flag to the template context so admins see a distinct "Admin preview" notice instead of the join flow.
- The editor options (gaps #6 and #7) and the broadcast action (gap #8) should be visible to admins regardless of membership status, labelled with an "(admin)" or preview badge so they can verify the UI without polluting their own relay write access.

---

## Relay configuration

### Essayist relay (`strfry-essayist`)

Docker profile `essayist` — activate with `docker compose --profile essayist up -d`.

| File | Purpose |
|---|---|
| `docker/strfry-essayist/strfry.conf` | Port 7779, NIP-11 metadata |
| `docker/strfry-essayist/write-policy.sh` | Calls internal API; accepts kind 30023 from members only |

### Caddy routing

`frankenphp/Caddyfile` contains `@essayistRelay` matcher routing `{$ESSAYIST_RELAY_DOMAIN}` → `strfry-essayist:7779` via WebSocket reverse proxy.

### Environment variables

| Variable | Dev default | Production |
|---|---|---|
| `ESSAYIST_RELAY_DOMAIN` | `essayist.localhost` | `essayist.decentnewsroom.com` |
| `ESSAYIST_POLICY_TOKEN` | `changeme` | random 32-byte hex secret |

---

## NIP-11 relay information

```json
{
  "name": "Essayist",
  "description": "A members-only Nostr relay for longform writing. Monthly membership fee goes directly to current members. Both reading and writing require active membership.",
  "pubkey": "d475ce4b3977507130f42c7f86346ef936800f3ae74d5ecf8089280cdc1923e9",
  "contact": "decentnewsroom.com",
  "supported_nips": [1, 2, 9, 11, 12, 15, 16, 20, 22],
  "software": "strfry",
  "limitation": {
    "payment_required": false,
    "auth_required": true,
    "restricted_writes": true
  }
}
```

`auth_required: true` and `restricted_writes: true` signal to clients that both reading and writing require active membership. Write gating is enforced at the relay protocol level via `write-policy.sh`. Read gating is currently enforced at the app layer only — full NIP-42 relay-level read enforcement is a future improvement (see open gap #4).
