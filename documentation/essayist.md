# Essayist

## Working Definition

**Essayist is a Nostr relay for longform writing where access works by contributing to the people already in the pool.**

To join, you send a small amount of sats distributed among the current members. That grants you one month of access. You can read. You can post. No approval required. No content gating. If you irritate people, they mute you, and you lose your audience. That is the only enforcement.

---

# 1. The Simple Explanation

## Short Version

Essayist is a Nostr relay for longform writing. To get in, you pay a small monthly contribution to someone in the pool. Once you are in, you can write and read freely. The community self-polices through muting.

## Even Shorter Version

Pay your share to someone in the pool. Publish or read. Do not be annoying or no one will see you.

## One-Line Pitch

**Essayist is a relay where your entry fee goes directly to the people already contributing.**

## Member Explanation

To access Essayist, you make a small contribution to someone in the current pool of members. This renews monthly. While your membership is active, you can publish longform articles to the relay and read everything on it. If someone is disruptive, other members mute them. The relay operator does not need to police content.

---

# 2. Core Principle

**The pool supports itself.**

Money flows between members, not to a platform. Everyone who participates is both a potential recipient and a contributor. The relay operator facilitates access and keeps the relay running; they do not act as editorial gatekeeper.

---

# 3. Minimal Product Scope

## Required

* Essayist landing page explaining the pool model.
* Nostr login (already built).
* Contribution flow: logged-in user chooses an amount, sends sats distributed across current pool members.
* Manual or semi-automatic verification of contribution.
* Access flag (`ROLE_ESSAYIST_MEMBER`) granted once contribution is verified, valid for the next month.
* Essayist feed page gated by `ROLE_ESSAYIST_MEMBER`.
* Relay write access also gated by `ROLE_ESSAYIST_MEMBER` (same role, no separate author check).
* Basic admin view for pending access requests and active members.

## Optional but Useful

* Public member count.
* Contribution history visible to the contributor.
* Automatic role expiry (cron job removes lapsed access).
* Landing page showing recent articles from the relay (public preview).

## Defer

* Algorithmic allocation.
* Cashu mint.
* Complex ledger.
* Automatic cartel detection.
* Many access tiers.
* Public audit system.
* Curated writer packs.

The first version only needs to answer:

**Did this person contribute to the pool this month?**

If yes, grant access for next month.

---

# 4. Access Model

## One Role: `ROLE_ESSAYIST_MEMBER`

There is a single role. It grants:

- read access to the gated DN feed page (`/essayist/feed`);
- write access to the Essayist relay (`wss://essayist.decentnewsroom.com`).

A member is anyone who has made a valid monthly contribution and whose access window has not expired.

## Contribution

The contribution is a small fixed amount of sats (set by the operator; suggested starting value: 1000–5000 sats per month). It is distributed among the current pool of active members at the time of payment. The split is even or proportional — operator decides the initial rule.

Mechanics:

1. Visitor logs in with their Nostr key.
2. They are shown the current pool size, the required contribution amount, and a payment interface.
3. They pay. Manual verification for now (admin runs `user:elevate <npub> ROLE_ESSAYIST_MEMBER`).
4. Access is active for the current month and the next.
5. At expiry, access lapses. The member can renew by contributing again.

## Content Enforcement

None. The relay accepts all kind 30023 from any member pubkey. No editorial review. No quality gate. If a member publishes content that others dislike, the community mutes them. A muted member still technically has access but has no effective audience.

The relay operator reserves the right to delete content for legal reasons.

---

# 9. Public Announcement Copy

## Option A

Launching Essayist: a Nostr relay for longform writing where your monthly contribution goes to the people already in the pool.

No writer approval. No reader tier. One role: member. Publish, read, stay in. Mute what you do not want to see.

Join at `decentnewsroom.com/essayist`.

## Option B

Essayist is a relay experiment.

The premise: what if the entry fee went directly to the participants instead of the platform? Monthly sats, distributed to current members. One month of full access. Renew to stay in.

No curation. No approval. The relay and its members govern by muting.

`decentnewsroom.com/essayist`

---

# 10. Technical Implementation Notes

> This section bridges the concept above with the actual state of the Newsroom codebase.

---

## Roles

A single role is needed:

- **`ROLE_ESSAYIST_MEMBER`**: granted when a contribution is verified. Grants both read access to the DN feed page and write access to the relay. Expires end of next month.

The previous `ROLE_ESSAYIST_CANDIDATE`, `ROLE_ESSAYIST_AUTHOR`, and `ROLE_ESSAYIST_SUPPORTER` roles are not used in this model and should not be created (or should be removed if already present).

## What is already built and can be reused directly

### Relay write-policy endpoint

**`GET /api/internal/essayist/writer/{pubkey}`** — already implemented. Checks whether a hex pubkey holds the required role. For the simplified model, update this endpoint to check `ROLE_ESSAYIST_MEMBER` instead of `ROLE_ESSAYIST_AUTHOR`. No structural changes needed beyond the role name.

### Nostr login

`NostrAuthenticator` (NIP-07 / NIP-46) is already implemented. No separate login flow needed.

### ZapButton component

`ZapButton.php` (Twig Live Component, `Molecules/`) can be used to generate the contribution payment. Show a list of current member pubkeys and let the members choose. Make zap receipts work.
### Admin role grant

The `user:elevate` CLI command sets roles on the `User` entity. `ROLE_ESSAYIST_MEMBER` can be granted exactly like any other role. Manual process: admin verifies contribution, runs `user:elevate <npub> ROLE_ESSAYIST_MEMBER`.

### Admin dashboard pattern

The existing admin dashboard follows a consistent pattern. An Essayist section should be added here with:
- Pending access requests (users who have initiated payment but not yet been verified)
- Active members (users with `ROLE_ESSAYIST_MEMBER`, sorted by expiry)
- Action to grant or revoke membership

---

## Critical gaps that block the launch model

### 1. Zap receipt detection is not working

The project backlog notes: "Apparently no zaps are ever found on relays. Review."

Until this is fixed, access granting is a manual admin step. The landing page shows the invoice; the admin approves once satisfied. Fix receipt detection separately before automating the gate.

### 2. Relay write-policy check

The existing write-policy endpoint checks `ROLE_ESSAYIST_AUTHOR`. Update it to check `ROLE_ESSAYIST_MEMBER`. The internal API contract does not change; only the role name checked server-side changes.

### 3. Role expiry

There is currently no mechanism to automatically remove `ROLE_ESSAYIST_MEMBER` when expired. This can be managed manually at first and automated later via a cron command that checks when the role was last granted and removes expired access.

---

## Relay configuration

### Essayist relay (`strfry-essayist`)

The Essayist relay is a dedicated strfry instance in `docker/strfry-essayist/`:

- **`strfry.conf`** — port 7779, NIP-11 info declares `name = "Essayist"`.
- **`write-policy.sh`** — rejects writes from pubkeys without `ROLE_ESSAYIST_MEMBER`. Accepted kinds: 30023 and any other longform kinds supported by the pool. Reads are open (any subscriber).
- **`GET /api/internal/essayist/writer/{pubkey}`** — internal API called by the write policy on every incoming `EVENT`. Checks `ROLE_ESSAYIST_MEMBER`. Protected by bearer token (`ESSAYIST_POLICY_TOKEN` env var).
- **Docker profile `essayist`** — activate with `docker compose --profile essayist up -d`.

### Public subdomain

```
wss://essayist.decentnewsroom.com
```

Caddy routes `essayist.decentnewsroom.com` to `strfry-essayist:7779` via the `@essayistRelay` matcher in `frankenphp/Caddyfile`.

### Environment variables

| Variable | Default (dev) | Production value |
|---|---|---|
| `ESSAYIST_RELAY_DOMAIN` | `essayist.localhost` | `essayist.decentnewsroom.com` |
| `ESSAYIST_POLICY_TOKEN` | `changeme` | random 32-byte hex secret |

---

## NIP-11 relay information

```json
{
  "name": "Essayist",
  "description": "A Nostr relay for longform writing. Monthly membership fee goes to current members. Write and read freely.",
  "pubkey": "d475ce4b3977507130f42c7f86346ef936800f3ae74d5ecf8089280cdc1923e9",
  "contact": "decentnewsroom.com",
  "supported_nips": [1, 2, 9, 11, 12, 15, 16, 20, 22],
  "software": "strfry",
  "limitation": {
    "payment_required": false,
    "auth_required": false,
    "restricted_writes": true
  }
}
```

`restricted_writes: true` signals to clients that write access requires active membership. Reads are unrestricted at the relay layer.

---

## Suggested minimal technical scope

1. **`ROLE_ESSAYIST_MEMBER` in `RolesEnum`** — one enum case. Grants relay write access and gated feed read access.

2. **Update write-policy endpoint** — change internal role check from `ROLE_ESSAYIST_AUTHOR` to `ROLE_ESSAYIST_MEMBER`.

3. **Landing page: `/essayist`** — publicly accessible:
   - Hero: headline, subheadline, CTA
   - How it works (four steps above)
   - Current member count (public)
   - Contribution interface (ZapButton or manual invoice display)
   - FAQ

4. **Feed page: `/essayist/feed`** — gated by `ROLE_ESSAYIST_MEMBER`. Queries `strfry-essayist` for kind 30023 events. Renders with existing `CardList` organism.

5. **Admin section: `/admin/essayist`** — pending requests + active members + grant/revoke actions.

6. **No separate writer/reader distinction.** No application queue. No editorial review. No content quality check.
