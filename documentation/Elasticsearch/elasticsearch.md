# Elasticsearch

## Overview

Optional search backend, feature-flagged via `ELASTICSEARCH_ENABLED` env var. When disabled, the app falls back to `DatabaseArticleSearch` and `DatabaseUserSearch`.

## Configuration

- Bundle: `friendsofsymfony/elastica-bundle` (`config/packages/fos_elastica.yaml`)
- Indexes: `articles`, `users`
- Providers: `App\Provider\ArticleProvider`, `App\Provider\UserProvider`

## Feature Flag

Set `ELASTICSEARCH_ENABLED=true` in `.env` to activate. The `ArticleSearchFactory` and `UserSearchFactory` check this flag at runtime.

## Draft Exclusion

Elasticsearch indexes only published articles (kind 30023). Drafts (kind 30024) are excluded from indexing via the article provider's query filter.

## Indexing

```bash
# Rebuild indexes
docker compose exec php bin/console fos:elastica:populate

# Index specific articles
docker compose exec php bin/console app:index-articles
```

