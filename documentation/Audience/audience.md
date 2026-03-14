# Audience Documentation

This section covers user-facing features including search, profiles, and authentication.

## Overview

The Newsroom application provides various features for the audience including anonymous search, user profiles, and NIP-46 remote signing for authentication.

## Key Documents

### Search
- **[search-anonymous-user-support.md](../Newsroom/search-anonymous-user-support.md)** - Anonymous user search support
- **[anonymous-search-quickref.md](../Newsroom/anonymous-search-quickref.md)** - Quick reference for anonymous search
- **[search-result-limits.md](../Newsroom/search-result-limits.md)** - Search result limits
- **[search-limits-implementation-summary.md](../Newsroom/search-limits-implementation-summary.md)** - Implementation summary
- **[search-refactoring-summary.md](../Newsroom/search-refactoring-summary.md)** - Refactoring documentation
- **[search-url-parameter-fix.md](../Newsroom/search-url-parameter-fix.md)** - URL parameter fixes
- **[fix-stale-search-cache.md](../Newsroom/fix-stale-search-cache.md)** - Cache fixes

### User Profiles
- **[AUTHOR_ABOUT_IMPLEMENTATION.md](../Newsroom/AUTHOR_ABOUT_IMPLEMENTATION.md)** - Author about page
- **[nip05-badge-component.md](../Nostr/nip05-badge-component.md)** - NIP-05 verification badge

### Authentication (NIP-46)
- **[nip46-final-implementation.md](../Nostr/nip46-final-implementation.md)** - NIP-46 implementation
- **[nip46-session-persistence-issue.md](../Nostr/nip46-session-persistence-issue.md)** - Session persistence
- **[nip46-session-reconnection-research.md](../Nostr/nip46-session-reconnection-research.md)** - Reconnection research
- **[nip46-solution-summary.md](../nip46-solution-summary.md)** - Solution summary

## Anonymous Search

Search functionality works for both authenticated and anonymous users:

```php
// Works for everyone
$results = $this->performOptimizedSearch($this->query);

// Credits only spent if user is authenticated
if ($isAuthenticated && $this->creditsManager->canAfford($this->npub, 1)) {
    $this->creditsManager->spendCredits($this->npub, 1, 'search');
}
```

## NIP-05 Badge Component

Automatic verification of NIP-05 identifiers:

```twig
<twig:Atoms:Nip05Badge 
    nip05="{{ author.nip05 }}" 
    pubkeyHex="{{ author.pubkey }}" 
/>
```

Features:
- Automatic `.well-known/nostr.json` validation
- 1-hour result caching
- Relay discovery support
- Graceful failure handling

## Author About Page

The author about page displays:
- Profile metadata with tag parsing
- Multiple NIP-05 identifiers support
- Multiple Lightning addresses support
- Raw event data for debugging

