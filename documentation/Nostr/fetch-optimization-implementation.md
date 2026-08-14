# Fetch Optimization

This page documents the current kind-bundle fetch strategy. The older phased plan has been removed; this is the living reference.

## Goal

Reduce relay round-trips by fetching related Nostr event kinds together and persisting everything useful from the response.

## Core Pieces

| Piece | Location | Purpose |
|---|---|---|
| `KindBundles` | `src/Enum/KindBundles.php` | Defines reusable kind groups and helper methods. |
| `UserProfileService::fetchUserContext()` | `src/Service/Nostr/UserProfileService.php` | Fetches user context kinds in one request and persists them. |
| `SocialEventService::fetchArticleSocial()` | `src/Service/Nostr/SocialEventService.php` | Fetches article reactions, comments, labels, zaps, and highlights together. |
| `FetchAuthorContentHandler` | `src/MessageHandler/FetchAuthorContentHandler.php` | Groups requested author content kinds into one relay request. |
| `FollowsRelayPoolService` | `src/Service/Nostr/FollowsRelayPoolService.php` | Builds a cached relay pool from followed authors' relay lists. |

## Kind Bundles

| Bundle | Kinds | Used For |
|---|---|---|
| `USER_CONTEXT` | metadata, follows, relay lists, mutes, pins, bookmarks, interests, Blossom server list | Login sync, settings, profile/user-context fallback. |
| `ARTICLE_SOCIAL` | reactions, comments, labels, zap requests, zap receipts, highlights | Article social context. |
| `AUTHOR_CONTENT` | longform, drafts, media, comments, highlights, bookmarks, curation sets, interest sets, playlists | Author profile/content hydration. |

Check `src/Enum/KindBundles.php` for the exact integer list.

## Runtime Rules

- Fetching one user-context kind on a DB miss should warm the rest of the user-context bundle.
- Article social fetches should prefer one `#A`-filtered bundle request over separate comments/highlights/zap requests.
- Author content hydration should group by requested kinds and route events back to their content type after the response.
- Relay pools derived from follows are cached by the user's current follows event id, so they rebuild only when the follows list changes.

## Local Relay Support

`docker/strfry/router.conf` mirrors the same idea by subscribing to user-context kinds from configured relay sources. This increases DB hit rates for profile, follows, relay-list, and interest lookups.

## Verification

```bash
docker compose exec php bin/phpunit tests/Unit/Enum/KindBundlesTest.php
docker compose exec php bin/phpunit tests/Unit/Service/Nostr/FollowsRelayPoolServiceTest.php
```