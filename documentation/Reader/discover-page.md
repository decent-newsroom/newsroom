# Discover Page

## Overview

The discover page (`/discover`) shows the latest articles from the local relay, with search integration and tag-based filtering.

## Architecture

- Articles served from Redis view store (`view:articles:latest`), with database fallback
- Bot filtering via `LatestArticlesExclusionPolicy` (denylist + profile bot flag)
- One article per author to ensure variety
- Paginated with Twig `Pagination` component

## Search Integration

The discover page includes the `SearchComponent` for inline article search. Search redirects from the discover page preserve context.

## Performance

- Redis cache rebuilt every 15 minutes by `CacheLatestArticlesCommand`
- Database queries use composite indexes on `kind` + `created_at`
- Article HTML is pre-rendered and cached to avoid markdown conversion on every request

