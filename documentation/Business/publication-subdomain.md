# Publication Subdomain Subscriptions

## Overview

Users can host their magazines on a custom subdomain of decentnewsroom.com via a paid subscription (Lightning payment). When active, an `UnfoldSite` is automatically created to serve the magazine on the subdomain.

## User Flow

There are two entry points to subdomain subscription:

### Standalone flow
1. **Landing page** (`/subscription/publication-subdomain`) — view pricing, existing subscription
2. **Subscribe** (`/subscription/publication-subdomain/subscribe`) — choose subdomain name, enter magazine coordinate
3. **Payment** (`/subscription/publication-subdomain/invoice`) — Lightning invoice QR code, 120,000 sats
4. **Settings** (`/subscription/publication-subdomain/settings`) — view subscription details, expiration, publication URL
5. **Cancel** (POST to `/subscription/publication-subdomain/cancel`) — cancel pending subscriptions to release subdomain reservation

### Magazine wizard flow (integrated)
1. **Wizard Step 5** (`/magazine/wizard/subdomain`) — subdomain picker with inline pricing; magazine coordinate auto-filled from draft
2. **Form posts to** `publication_subdomain_create` — creates subscription, generates Lightning invoice
3. **Payment** (`/subscription/publication-subdomain/invoice`) — same invoice page as standalone
4. Error redirects return to wizard step 5 (via `_error_redirect` hidden field) instead of standalone subscribe page

## Architecture

### Key Files

| Component | File |
|-----------|------|
| Entity | `src/Entity/PublicationSubdomainSubscription.php` |
| Status enum | `src/Enum/PublicationSubdomainStatus.php` (PENDING, ACTIVE, EXPIRED, CANCELLED) |
| Repository | `src/Repository/PublicationSubdomainSubscriptionRepository.php` |
| Service | `src/Service/PublicationSubdomainService.php` |
| User controller | `src/Controller/Subscription/PublicationSubdomainController.php` |
| Admin controller | `src/Controller/Administration/PublicationSubdomainAdminController.php` |

### Configuration (`services.yaml`)

```yaml
App\Service\PublicationSubdomainService:
    arguments:
        $baseDomain: '%base_domain%'
        $recipientLud16: '%active_indexing_recipient_lud16%'
```

Reuses the same Lightning payment address as Active Indexing and Vanity Names.

### Admin Features

Route: `/admin/publication-subdomains`
- List all subscriptions with status filters
- View subscription details
- Manual activation for testing

### Subdomain Availability

`isSubdomainAvailable()` only considers PENDING and ACTIVE subscriptions as blocking — CANCELLED and EXPIRED subdomains can be reused.

## Lessons Learned

- **Redirect loop on cancelled subscriptions**: The subscribe controller originally redirected ANY existing subscription to settings, including cancelled ones. Settings then redirected cancelled back to index, creating a loop. Fix: check subscription *status*, not just existence — only redirect ACTIVE/PENDING to settings.
- **Ephemeral notice**: After cancellation, the index page shows an info notice about the previous subscription and allows re-subscribing.
- **Service config required**: `PublicationSubdomainService` needs explicit `services.yaml` configuration for `$baseDomain` and `$recipientLud16` — Symfony can't autowire scalar parameters.

