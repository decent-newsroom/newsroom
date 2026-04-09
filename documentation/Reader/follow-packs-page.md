# Follow Packs Page

## Overview

The Follow Packs page (`/follow-packs`) is a public listing of curated writer collections (Nostr kind 39089 events). It displays follow packs whose members have collectively published more than 5 articles in the local index, ensuring that only packs with meaningful content are shown.

## Routes

| Method | Path | Name | Description |
|--------|------|------|-------------|
| `GET` | `/follow-packs` | `follow_packs` | Public follow packs listing page |
| `GET` | `/follow-pack/{npub}/{dtag}` | `follow_pack_view` | Individual follow pack view with articles (paginated) |

## Navigation

A "Follow Packs" link appears in the sidebar navigation under the Newsroom section, immediately after "Collections". It is visible to all users (logged-in and anonymous).

## Architecture

### Component: `FollowPackList`

- **PHP class:** `src/Twig/Components/Organisms/FollowPackList.php`
- **Template:** `templates/components/Organisms/FollowPackList.html.twig`
- **Type:** Twig Component (Organism)

The component:

1. Fetches all kind 39089 events from the database
2. Deduplicates by `pubkey:d-tag` (keeps the latest version)
3. Extracts metadata from tags: `title`, `description`, `image`, `d` (slug), `p` (member pubkeys)
4. Counts total deduplicated articles per pack using `countTotalArticlesForPubkeys()` (uses `SELECT DISTINCT pubkey, slug` subquery to avoid revision inflation)
5. Filters to packs where the total exceeds 5
6. Sorts by total article count (most content first)
7. Resolves curator profile metadata from Redis cache

### Article Counting (revision-safe)

A subquery counts `DISTINCT (pubkey, slug)` pairs to avoid inflated counts from article revisions:

```sql
SELECT COUNT(*) AS cnt FROM (
    SELECT DISTINCT pubkey, slug
    FROM article
    WHERE pubkey IN (...)
      AND slug IS NOT NULL
      AND title IS NOT NULL
      AND kind != 30024
) AS unique_articles
```

This is exposed via `ArticleRepository::countTotalArticlesForPubkeys()`.

### Article Listing (deduplicated + paginated)

The follow pack view page uses `ArticleRepository::findAllByPubkeysDeduplicated()` which leverages PostgreSQL `DISTINCT ON (pubkey, slug)` to collapse revisions and return only the latest version of each article:

```sql
SELECT id FROM (
    SELECT DISTINCT ON (pubkey, slug) id, created_at
    FROM article
    WHERE pubkey IN (...)
      AND slug IS NOT NULL AND title IS NOT NULL AND kind != 30024
    ORDER BY pubkey, slug, created_at DESC
) AS deduped
ORDER BY created_at DESC
```

Results are paginated at 20 per page using Pagerfanta with the `Atoms:Pagination` component.

### Card Display

Each follow pack card shows:
- **Cover image** (if available) — links to the pack's article list
- **Title** — links to the pack's article list (`/follow-pack/{npub}/{dtag}`)
- **Description** (if available)
- **Member count** badge (number of `p` tags)
- **Article count** badge (deduplicated total across all members)
- **Curator** — rendered via `UserFromNpub` molecule

## Translations

Translation keys are under the `followPacks` namespace:

| Key | English |
|-----|---------|
| `followPacks.heading` | Follow Packs |
| `followPacks.eyebrow` | curated writer collections |
| `followPacks.noPacks` | No follow packs with articles yet. |
| `followPacks.writers` | writers |
| `followPacks.articles` | articles |
| `followPacks.curatedBy` | Curated by |
| `nav.followPacks` | Follow Packs |

All six locales (en, de, es, fr, it, sl) are covered.
