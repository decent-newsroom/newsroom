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

### Blocked domains and automatic admin mutes

QA rejects links and images hosted on `y5.pics`, `go.cbrop.com`, or `i.postimg.cc`, including their subdomains. Matching is case-insensitive and checks article content, cover images, titles, summaries, and original event tag values. Markdown images, HTML links/images, protocol-relative URLs, and bare domain links are covered. Similar domain names and occurrences only in another host's URL path/query do not trigger rejection. Links are not fetched or followed.

Before approving new articles, `articles:qa` revisits matching stored articles at every QA status, excluding private drafts. It adds `ROLE_MUTED` to each matching author's local user record (creating an npub-only record if needed), preserves existing roles, and invalidates the shared mute cache. The existing muted-author cleanup then marks **all** of that author's articles `DO_NOT_INDEX` and removes their Elasticsearch article documents when enabled. This runs automatically as the first step of `articles:post-process`; no separate cleanup is needed.

The shared indexability callback and direct `articles:index` command also reject blocked-domain articles even if QA was skipped. Automatic author muting happens in QA, not in the indexability callback. These are instance-wide admin mutes, not signed NIP-51 personal mute events, ingestion bans, or deletion of the original events. Admins can remove the role through existing mute administration, but QA will mute the author again while their matching published articles remain stored.

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
