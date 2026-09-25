# Elasticsearch

## Overview

Optional search backend, feature-flagged via `ELASTICSEARCH_ENABLED`. When disabled, the app falls back to `DatabaseArticleSearch` and `DatabaseUserSearch`.

## Configuration

- Bundle: `friendsofsymfony/elastica-bundle` (`config/packages/fos_elastica.yaml`)
- Indexes: `articles`, `users`
- Providers: `App\Provider\ArticleProvider`, `App\Provider\UserProvider`

## Feature Flag

Set `ELASTICSEARCH_ENABLED=true` in `.env` to activate. The `ArticleSearchFactory` and `UserSearchFactory` check this flag at runtime.

## Article Exclusion

Elasticsearch indexes published articles (kind 30023). Drafts (kind 30024) and articles marked `DO_NOT_INDEX` are excluded by `IndexableArticleChecker`. The checker also excludes articles whose authors currently have `ROLE_MUTED`, including during a full index population.

`articles:qa` marks muted authors' articles `DO_NOT_INDEX`, including articles that passed QA before the author was muted. When Elasticsearch is enabled, QA removes those authors' existing article documents from the index. The direct `articles:index` command checks the mute list again before persisting each batch and marks any muted articles `DO_NOT_INDEX`.

## Indexing

```bash
# Rebuild indexes
docker compose exec php bin/console fos:elastica:populate

# Check article quality and mute status
docker compose exec php bin/console articles:qa

# Index selected articles
docker compose exec php bin/console articles:index
```

The `admin:delete-muted-events` command removes matching article documents from Elasticsearch before deleting database rows. See [Bulk Event Deletion](../Admin/deletion-optimization.md).
