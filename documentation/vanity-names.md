# Vanity links (NIP-05)

## Overview

The goal is to have users sign up to reserve a vanity name, 
then DN awards them the NIP-05 ident and maps their profile url aliased to their vanity name, so that links with their npub and the links with the vanity name lead to the same place.

This is a paid feature. The vanity name registration process can be implemented as a subscription or one-time payment, and the vanity name will be reserved for the user as long as they maintain their subscription or until they choose to release it.

There should also exist an administration page. This page will allow the admin to view all registered vanity names, their associated pubkeys, and the status of their subscriptions. The admin can also manually release vanity names if necessary.
And an admin can register vanity names on behalf of users, which can be useful for customer support or special cases.

## NIP-05 Specification Compliance

According to NIP-05, the vanity name system must:

1. **Serve `/.well-known/nostr.json`**: Return a JSON document with `names` mapping vanity names to hex public keys
2. **Include relay information** (optional): Return preferred relays in the `relays` field
3. **Valid characters**: Vanity names can only contain `a-z0-9-_.` (case-insensitive)
4. **CORS headers**: Must return `Access-Control-Allow-Origin: *` header
5. **No redirects**: The endpoint must NOT return HTTP redirects
6. **Hex format**: Public keys must be returned in hex format, not npub

### Example Response

```json
{
  "names": {
    "alice": "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
  },
  "relays": {
    "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9": [
      "wss://relay.damus.io",
      "wss://nos.lol"
    ]
  }
}
```

## Architecture

### Entity: VanityName

| Field | Type | Description |
|-------|------|-------------|
| id | int | Primary key |
| vanityName | string | The reserved vanity name (unique, lowercase, validated) |
| npub | string | The user's npub (bech32 format) |
| pubkeyHex | string | The user's public key in hex format (for NIP-05 response) |
| status | VanityNameStatus | pending, active, suspended, released |
| paymentType | VanityNamePaymentType | subscription, one_time, admin_granted |
| expiresAt | DateTime | When the vanity name reservation expires (null for one_time/admin_granted) |
| createdAt | DateTime | When the vanity name was reserved |
| updatedAt | DateTime | Last update timestamp |
| relays | JSON | Optional preferred relays for NIP-05 response |

### Enum: VanityNameStatus

| Value | Description |
|-------|-------------|
| pending | Awaiting payment confirmation |
| active | Vanity name is active and can be used |
| suspended | Temporarily suspended (admin action or payment issue) |
| released | User released the vanity name or it expired |

### Enum: VanityNamePaymentType

| Value | Description |
|-------|-------------|
| subscription | Monthly/yearly recurring payment |
| one_time | Single lifetime payment |
| admin_granted | Free grant by admin |

## Implementation Steps

### 1. Database Layer

1. **Create VanityName Entity**: Store vanity name to pubkey mappings with status and payment info
2. **Create VanityNameRepository**: Methods for lookups by vanity name, npub, status
3. **Create Migration**: Database schema for the vanity_name table

### 2. NIP-05 Endpoint

1. **Create WellKnownController**: Serve `/.well-known/nostr.json`
2. **Dynamic generation**: Query VanityNameRepository for active vanity names
3. **Cache response**: Cache the JSON response with a short TTL (5 minutes)
4. **CORS headers**: Add proper CORS headers

### 3. URL Routing

1. **Add vanity profile route**: `/p/{vanityName}` for vanity URLs
2. **Route resolution order**:
   - Check if identifier matches `npub1*` pattern → existing npub route
   - Check if identifier exists in VanityName table → resolve to profile
   - Return 404 if not found
3. **Canonical URL handling**: When displaying profile, prefer vanity URL if available

### 4. User Registration Flow

1. **Registration page**: `/vanity/register` - Form to request a vanity name
2. **Availability check**: AJAX endpoint to check if a name is available
3. **Payment flow**: Generate Lightning invoice (similar to ActiveIndexing)
4. **Activation**: Activate vanity name upon payment confirmation

### 5. Admin Panel

1. **List all vanity names**: `/admin/vanity` - Table with all registered names
2. **Search and filter**: By status, npub, vanity name
3. **Manual actions**: Activate, suspend, release vanity names
4. **Create for user**: Admin can assign vanity names to users directly

### 6. Service Layer

1. **VanityNameService**: Core business logic
   - `isAvailable(name)`: Check if a vanity name is available
   - `reserve(npub, name)`: Create pending reservation
   - `activate(vanityName)`: Activate after payment
   - `suspend(vanityName)`: Suspend a vanity name
   - `release(vanityName)`: Release a vanity name
   - `getByVanityName(name)`: Find by vanity name
   - `getByNpub(npub)`: Find by user npub

### 7. Integration Points

1. **Profile templates**: Update to show vanity URL if available
2. **Author section**: Display vanity name with NIP-05 styling
3. **Share buttons**: Use vanity URL in share links when available
4. **Article URLs**: Keep existing `/p/{npub}/d/{slug}` but add redirect from `/p/{vanityName}/d/{slug}`

## File Structure

```
src/
├── Entity/
│   └── VanityName.php
├── Enum/
│   ├── VanityNameStatus.php
│   └── VanityNamePaymentType.php
├── Repository/
│   └── VanityNameRepository.php
├── Service/
│   └── VanityNameService.php
├── Controller/
│   ├── Api/
│   │   └── WellKnownController.php
│   ├── Administration/
│   │   └── VanityNameAdminController.php
│   └── VanityNameController.php
└── AuthorController.php (vanity profile redirect route added)

templates/
├── vanity/
│   ├── index.html.twig
│   ├── invoice.html.twig
│   └── settings.html.twig
└── admin/
    └── vanity/
        ├── index.html.twig
        ├── show.html.twig
        └── create.html.twig

migrations/
└── Version20260207120000.php

config/
└── services.yaml (vanity_server_domain and recipientLud16 added)
```

## Implementation Status

### Completed ✅

1. **Entity & Enums**: `VanityName`, `VanityNameStatus`, `VanityNamePaymentType`
2. **Repository**: `VanityNameRepository` with all query methods
3. **Service**: `VanityNameService` with full business logic including:
   - Invoice creation via LNURL
   - `reserveWithInvoice()` - Reserve and create invoice in one step
   - `createInvoice()` - Generate Lightning invoice
   - `cancelPending()` - Cancel pending reservations
   - `hasActiveVanityName()` / `hasPendingVanityName()` - Status checks
4. **NIP-05 Endpoint**: `WellKnownController` serving `/.well-known/nostr.json`
5. **User Controller**: `VanityNameController` in `/subscription/vanity` with:
   - Registration with inline invoice generation
   - Payment status checking (AJAX)
   - Cancel pending functionality
   - Renewal flow with new invoice
6. **Admin Controller**: `VanityNameAdminController` for management
7. **Vanity Profile Routing**: Added to `AuthorController`
8. **Templates**: All user and admin templates with QR codes and payment status
9. **Lightning Payment Integration**: Using same LNURL flow as ActiveIndexing
10. **Migration**: Database schema for PostgreSQL
11. **CLI Commands**:
    - `vanity:check-receipts` - Check for zap receipts matching pending invoices
    - `vanity:process-expired` - Release expired subscription vanity names
    - `vanity:activate <name>` - Manually activate a vanity name
    - `vanity:list` - List all vanity names with stats

### TODO 📋

1. **Profile Integration**: Show vanity URL in profile share buttons
2. **Email/Push Notifications**: Notify users before expiration
3. **Cron Setup**: Configure cron jobs for `vanity:check-receipts` and `vanity:process-expired`

## CLI Commands

```bash
# Check for zap receipts and activate paid vanity names (run every 5 minutes)
php bin/console vanity:check-receipts --since-minutes=30

# Process expired subscriptions (run daily)
php bin/console vanity:process-expired

# Manually activate a vanity name
php bin/console vanity:activate alice

# List all vanity names
php bin/console vanity:list

# List only pending vanity names
php bin/console vanity:list --status=pending
```

## Pricing Considerations

| Payment Type | Price (sats) | Duration | Notes |
|--------------|-------------|----------|-------|
| subscription | 5,000 | 1 month | Auto-renew or release |
| one_time | 100,000 | Lifetime | One-time payment |
| admin_granted | 0 | Lifetime | Admin discretion |

## Security Considerations

1. **Name validation**: Only allow `a-z0-9-_.` characters, lowercase only
2. **Reserved names**: Block certain names (admin, system, support, etc.)
3. **Rate limiting**: Limit registration attempts per user/IP
4. **Impersonation prevention**: Admin review for names that might impersonate known figures

## Reserved Vanity Names

The following vanity names should be blocked from public registration:

- admin, administrator, system, support, help
- nostr, bitcoin, lightning, zap, btc, ln
- root, moderator, mod, staff, team, official
- api, www, mail, ftp, dns, ns1, ns2
- _, null, undefined, test, demo
- The server domain name and common variations
- Names of well-known Nostr figures (optional, admin discretion)
