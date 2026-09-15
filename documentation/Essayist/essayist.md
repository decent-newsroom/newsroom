# Essayist

Essayist is a membership relay and reading space for longform writing. Contributions go to existing members. The application maintains membership grants and checks access to member feeds; the public relay gateway enforces membership through NIP-42.

## Membership

`EssayistMembershipService` records contributions in `essayist_membership`, grants `ROLE_ESSAYIST_MEMBER`, and updates the relay membership cache. The minimum is configured by `essayist.membership.minimum_sats` (default 1,000 sats).

The calendar rule sets access through the end of the month after payment. Contributions in the same month converge on that date; they do not stack extra months. A later existing expiry is preserved. Reprocessing the same grant receipt ID is a no-op.

`expireLapsed()` removes membership from lapsed users and invalidates cached access. The current implementation exempts `ROLE_ESSAYIST_EARLY_BIRD` users. The early-bird claim endpoint still exists; its role behavior should not be confused with the old time-limited launch announcement.

## Application surfaces

| Surface | Purpose and access |
|---|---|
| `/essayist` | Public landing page and membership entry points. |
| `POST /essayist/request-access` | Authenticated, CSRF-protected candidate registration. |
| `POST /essayist/early-bird` | Authenticated, CSRF-protected early-bird role grant. |
| `/essayist/members` | Member directory and contribution/claim controls for candidates, members, and admins. |
| `/essayist/feed` | Latest relay articles for members and admins. |
| `/essayist/home` | Personalized feed and member activity for members and admins. |
| `/admin/essayist` | Operator membership administration. |

Contributions may be processed by the receipt worker or through [zap claims and confirmation](essayist-zap-claims.md). The editor and article actions also support publishing/broadcasting to Essayist. [Exclusive articles](exclusive-articles.md) documents the local serving flag and its limits.

## Relay architecture

The public WebSocket path is Caddy → `essayist-gateway` → `strfry-essayist`. The Go gateway authenticates public clients and checks membership before proxying their WebSocket traffic. Its implementation is present; relay-level authentication is no longer a pending design item.

Trusted application reads use the internal relay URL through `EssayistFeedService`, backed by NostrClientBundle/Innis. Internal relay access depends on the Docker network boundary and application authorization. Local article visibility controls are distinct from public relay authentication.

The development services use the `essayist` Compose profile; production overrides activate them. See [gateway configuration and troubleshooting](gateway.md).

## Key files

- [Controller](../../src/Controller/EssayistController.php)
- [Membership service](../../src/Service/Essayist/EssayistMembershipService.php)
- [Membership cache](../../src/Service/Essayist/EssayistMembershipCacheService.php)
- [Feed service](../../src/Service/Essayist/EssayistFeedService.php)
- [Home and activity](home.md)
- [Member relay pool](member-relay-pool.md)
