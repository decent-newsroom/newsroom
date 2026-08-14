# Search Documentation

Current search code should use `ContentSearchService` as the application-facing API.

## Documents

| Document | Purpose |
|---|---|
| [Content Search API](content-search-api.md) | Living reference for `ContentSearchService`. |
| [Quick Reference](QUICK_REFERENCE.md) | Short usage snippets. |
| [Advanced Search](../Reader/advanced-search.md) | Filter object and advanced article search notes. |
| [Elasticsearch](../Elasticsearch/elasticsearch.md) | Optional Elasticsearch backend setup. |

## Architecture

```text
Controller / Component / Command
  -> ContentSearchService
  -> ArticleSearchInterface
  -> ElasticsearchArticleSearch or DatabaseArticleSearch
```

## Rule Of Thumb

- Use `ContentSearchService` in new product code.
- Extend `ContentSearchService` when the app needs a new search operation.
- Touch `ArticleSearchInterface` only when changing backend capabilities.