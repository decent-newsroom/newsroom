# User Roles: Featured Writers & Muted Users

Manage special user roles for content curation and filtering.

## Overview

This feature provides two special roles that can be assigned to users:

1. **Featured Writers** (`ROLE_FEATURED_WRITER`) - Displayed in the sidebar for discovery
2. **Muted Users** (`ROLE_MUTED`) - Excluded from article listings (discover, latest articles)

## Managing Users via Admin UI

Go to **Admin → Roles** (`/admin/role`) to manage both featured writers and muted users:

### Featured Writers
- **Add**: Enter an npub in the form and click "Add Featured Writer"
- **Remove**: Click the "Remove" button next to their name
- Users with this role appear in the sidebar on key pages

### Muted Users
- **Add**: Enter an npub in the form and click "Add Muted User"
- **Remove**: Click the "Unmute" button next to their name
- Users with this role are excluded from article listings
- The muted pubkeys cache is automatically refreshed when changes are made

## Console Commands

Use the existing `user:elevate` command:

```bash
# Add featured writer role
php bin/console user:elevate npub1abc...xyz ROLE_FEATURED_WRITER

# Add muted role
php bin/console user:elevate npub1abc...xyz ROLE_MUTED
```

Note: When using the console command for muted users, you may need to clear the cache manually or wait for it to expire.

## Architecture

### User Entity
**File:** `src/Entity/User.php`

Constants and helper methods:
- `ROLE_FEATURED_WRITER` constant
- `ROLE_MUTED` constant
- `isFeaturedWriter()` method
- `isMuted()` method

### Repository
**File:** `src/Repository/UserEntityRepository.php`

Methods:
- `findFeaturedWriters()` - Get all users with featured writer role
- `findTopFeaturedWriters(int $limit)` - Get top N writers by recent article
- `findMutedUsers()` - Get all users with muted role
- `getMutedPubkeys()` - Get hex pubkeys of muted users

### MutedPubkeysService
**File:** `src/Service/MutedPubkeysService.php`

Manages the cached list of muted pubkeys:
- `getMutedPubkeys()` - Get from cache or refresh
- `refreshCache()` - Force refresh the cache
- `invalidateCache()` - Invalidate the cache

### RoleController
**File:** `src/Controller/Administration/RoleController.php`

Admin routes:
- `GET /admin/role` - View all roles, featured writers, and muted users
- `POST /admin/featured-writers/add` - Add featured writer
- `POST /admin/featured-writers/remove/{id}` - Remove featured writer
- `POST /admin/muted-users/add` - Add muted user (refreshes cache)
- `POST /admin/muted-users/remove/{id}` - Remove muted user (refreshes cache)

### DefaultController
**File:** `src/Controller/DefaultController.php`

Uses `MutedPubkeysService` to get excluded pubkeys for:
- `/discover` - Discover page
- `/latest-articles` - Latest articles page

## Caching

### Featured Writers
Writer metadata is cached using the `npub.cache` Redis pool with 1-hour TTL.

### Muted Pubkeys
Muted pubkeys are cached with 24-hour TTL. The cache is automatically refreshed when:
- A user is added to the muted list via admin UI
- A user is removed from the muted list via admin UI

## Migration

If you previously had hardcoded excluded npubs, you can migrate them to the database:

```bash
# Add each previously hardcoded npub as muted
php bin/console user:elevate npub1etsrcjz24fqewg4zmjze7t5q8c6rcwde5zdtdt4v3t3dz2navecscjjz94 ROLE_MUTED
php bin/console user:elevate npub1m7szwpud3jh2k3cqe73v0fd769uzsj6rzmddh4dw67y92sw22r3sk5m3ys ROLE_MUTED
# ... etc
```

Or use the admin UI at `/admin/role` to add them one by one.

