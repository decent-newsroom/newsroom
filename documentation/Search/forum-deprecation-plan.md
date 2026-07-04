# Forum Deprecation & Topic Integration Plan

## Motivation

The current forum architecture is topic-centric but isolated from the main content feeds. As we scale, users benefit more from:
1. **Topics integrated into main feeds** — discovering topics while browsing home timeline
2. **Related articles on article pages** — contextual suggestions based on shared tags
3. **Unified search/filter UI** — topic filtering available everywhere, not just `/forum`
4. **Simplified administration** — topic management via search/tag UI instead of dedicated forum section

The new `ContentSearchService` API enables this transition without breaking existing functionality.

## Goals

- ✅ Move topic-based content discovery from dedicated forum pages to distributed feed/article locations
- ✅ Maintain 100% backward compatibility during transition (existing forum URLs work)
- ✅ Provide unified topic/tag management UI across the app
- ✅ Enable power users to maintain personal interest topics (kind 10015 → "My Interests")
- ✅ Keep local relay/curation features (magazines, reading lists) independent

## Non-Goals

- **Removing topics entirely** — topics remain as content metadata (tags)
- **Removing user interests** — kind 10015 remains and is enhanced
- **Removing magazines/reading lists** — these are distinct features
- **Removing local relay topics** — those stay as per-instance curation

## Timeline

### Phase 1: API & Parallel Rendering (Weeks 1-3)
**Status**: ✅ Complete (ContentSearchService implemented)

**Deliverables:**
- [x] `ContentSearchService` implemented with comprehensive API
- [x] API documentation written (`documentation/Search/content-search-api.md`)
- [x] Migration guide written (`documentation/Search/migration-to-content-search-api.md`)
- [ ] Forum controllers refactored to use `ContentSearchService`
- [ ] Topic widgets rendered on main feed (via Turbo Frame)
- [ ] Related articles component deployed to article pages
- [ ] Admin panel gains topic management UI (alpha)

**UI Changes:**
- `/forum` → displays via old layout (no visual change)
- Home feed → shows "Explore Topics" Turbo Frame section
- Article pages → show "Related by topic" section

**User Impact:** Minimal — new features appear, forum works as before.

---

### Phase 2: Feed Integration (Weeks 3-5)
**Status**: Pending

**Deliverables:**
- [ ] Article pages show topic breadcrumbs + related articles sidebar
- [ ] Search page enhanced with topic filters + live tag suggestions
- [ ] Admin panel: full topic browsing/filtering UI
- [ ] "My Interests" (kind 10015) page stays on its current dedicated flow for now

**UI Changes:**
- Topic breadcrumbs on article pages link to topic feed
- Topic filtering becomes available in article, search, and admin surfaces first
- Forum topic pages remain live but show notice: "Viewing via legacy forum. Try the [new topic view]"

**User Impact:** Medium — new navigation paths appear. Power users migrate to new topic UI.

---

### Phase 3: Soft Deprecation (Weeks 5-8)
**Status**: Pending

**Deliverables:**
- [ ] Forum pages (`/forum`, `/forum/topic/...`, `/forum/tag/...`) display deprecation notice
- [ ] Notice links to equivalent new topic filtering in home feed
- [ ] Legacy forum routes redirect with 307 to new topic view (e.g., `/forum/topic/bitcoin-lightning` → `/home?tab=topics&topics=bitcoin,lightning`)
- [ ] Analytics show forum page traffic migration to home feed topics
- [ ] Documentation updated (remove forum-specific docs, consolidate to topics docs)

**UI Changes:**
- /forum pages show banner: "This page has moved. Explore topics in your [home feed]"
- Old routes still work but redirect
- Breadcrumbs updated on all pages

**User Impact:** Low — users redirected automatically, feature parity maintained.

---

### Phase 4: Removal (v1.0+)
**Status**: Future

**Deliverables:**
- [ ] Entire `ForumController` removed from codebase
- [ ] `ForumTopics` taxonomy moved to `TopicRegistry` (if still needed)
- [ ] Legacy forum routes removed
- [ ] Tests updated

**User Impact:** None for compliant users. Users with bookmarked `/forum` URLs see 404 (with link to home feed).

---

## Integration Points

### 1. Home Feed — Topics Tab

```
Home Feed (existing)
├── Following Tab (existing)
├── Latest Tab (existing)
├── Bookmarks Tab (existing)
├── Topics Tab ← NEW
│   ├── Topic browser (all topics in registry)
│   ├── Topic filters (multi-select)
│   └── Results: articles matching selected topics
└── Interests Tab (kind 10015, redesigned)
    ├── My Interests (loose tags)
    ├── Interest Sets (kind 30015)
    └── Results: articles matching user's interests
```

**UI Component**: `HomeFeedTopicsTab.php` (Twig Live Component)
- Lazy-loads topic list via `ContentSearchService::buildTaxonomyWithCounts()`
- Turbo Frame for framed rendering
- Multi-select topic picker
- Real-time or cached article results

**Backend**: Use `ContentSearchService::searchByTopics()`

---

### 2. Article Pages — Related Articles + Topic Breadcrumbs

```
Article Page Layout
├── Article Body
├── Metadata
│   └── Topic Tags ← Shows as clickable breadcrumbs via ContentSearchService
├── Related Articles Section ← NEW
│   ├── "Articles on these topics:"
│   └── Grid of related articles via ContentSearchService::findRelatedArticles()
└── Comments/Highlights
```

**UI Component**: `RelatedArticles.php` (existing, enhanced) + `ArticleTopicBreadcrumbs.php` (new)

**Backend implementation**:
```php
// In ArticleController or Twig component
$related = $this->contentSearch->findRelatedArticles($article, limit: 6);
$topicCounts = $this->contentSearch->getTopicsMetadata($article->getTopics() ?? []);
```

---

### 3. Search Page — Topic Filters

**Current**: `/search?q=...` text search only

**Enhanced**:
```
Search Page (Phase 2)
├── Search Box ← existing
├── Filters Sidebar ← NEW
│   ├── Topic/Tag Filter (multi-select, grouped by category)
│   ├── Date Range (existing)
│   ├── Author (existing)
│   └── Sort By (existing)
└── Results + Facets
    ├── Articles (filtered)
    └── Popular Topics in Results ← NEW
```

**Backend**: Extend `AdvancedSearch` to include topic selection from UI.

---

### 4. Admin Panel — Topic Management

**Current**: No admin topic UI

**Phase 2**: Simple dashboard showing:
- All topics and article counts
- Top topics by volume
- Topic rename/alias suggestions
- Topic hiding (add to `HiddenCoordinate` if needed)
- Bulk topic actions

**Implementation**: Admin controller + Twig component using `ContentSearchService::buildTaxonomyWithCounts()`.

---

### 5. "My Interests" Page — Redesigned

**Current**: `/my-interests` with forum-like category layout

**Phase 2**: Redesigned to match new topic UI:
- Sync with kind 10015 events
- Show user's loose interest tags + interest sets (kind 30015)
- Filter articles by user interests
- Add/remove interests inline (live component)
- Create new interest sets

**Backend**: Enhance using `ContentSearchService::searchByTopics()`.

---

## The "Related Articles" Feature

A key integration point — articles now show related content based on shared topics.

### Algorithm

1. Extract tags from current article: `$article->getTopics()`
2. Search for articles sharing those tags: `ContentSearchService::searchByTopics()`
3. Filter out current article (pubkey + slug)
4. Limit to 6 results
5. Render as grid of cards

**Component**: `src/Twig/Components/Organisms/RelatedArticles.php`

```php
public function mount(Article $article): void
{
    $this->articles = $this->contentSearch->findRelatedArticles($article, limit: 6);
}
```

**Template**: `templates/components/Organisms/RelatedArticles.html.twig`
```twig
{% if articles %}
<section class="related-articles">
    <h3>{{ 'articles.related_title'|trans }}</h3>
    <div class="article-grid">
        {% for article in articles %}
            <twig:Molecules:Card :article="article" />
        {% endfor %}
    </div>
</section>
{% endif %}
```

---

## Implementation Strategy

### Step 1: Migrate Forum Controllers (Phase 1)
Refactor `ForumController`, `RelatedArticles`, and related components to use `ContentSearchService`.

See: `documentation/Search/migration-to-content-search-api.md`

### Step 2: Deploy Related Articles (Phase 1)
1. Create/update `RelatedArticles` component to use `ContentSearchService::findRelatedArticles()`
2. Add to article page template
3. Test with real articles
4. Cache results if needed

### Step 3: Build Topics Tab (Phase 2)
1. Create `HomeFeedTopicsTab` Twig Live Component
2. Fetch taxonomy via `ContentSearchService::buildTaxonomyWithCounts()`
3. Implement topic multi-select filter
4. Search by selected topics
5. Integrate into home feed layout

### Step 4: Add Admin UI (Phase 2)
1. Create admin topic management controller
2. List topics with counts
3. Add hide/alias functionality
4. Monitor topic health

### Step 5: Soft Deprecation (Phase 3)
1. Add deprecation notice to forum pages
2. Redirect forum routes to equivalent topic views
3. Monitor traffic migration

### Step 6: Removal (Phase 4)
1. Delete forum routes and controller
2. Remove `ForumTopics` if replaced by `TopicRegistry`
3. Update documentation

---

## Backward Compatibility

**During Phases 1-3**: All existing forum URLs continue to work
- `/forum` → works, shows deprecation notice
- `/forum/topic/{key}` → works, shows deprecation notice
- `/my-interests` → enhanced but backward compatible
- `/api/interests/*` → unchanged

**After Phase 4**: Old URLs return 404 with helpful message pointing to home feed.

---

## Configuration

No new environment variables needed. Uses existing:
- `ELASTICSEARCH_ENABLED` — search backend selection
- Existing caching configuration

Optional future: `FORUM_DEPRECATED=true` to skip soft-deprecation phase.

---

## Monitoring & Metrics

### Key Metrics to Track

1. **Usage Migration**
   - Forum page views trend over time
   - Home feed topic tab engagement
   - Article page "related articles" clicks
   - Search filter usage

2. **Quality**
   - Related articles relevance (user feedback)
   - Search latency
   - Topic count accuracy

3. **User Experience**
   - Time to discover topics (old vs new)
   - Click-through rate on related articles
   - Feedback on UI changes

### Logging

```php
// ContentSearchService logs all queries at INFO level
$this->logger->info('searchByTopics called', [
    'topics' => ['bitcoin', 'lightning'],
    'limit' => 20,
    'backend' => 'elasticsearch',
]);
```

---

## FAQ

**Q: Will my forum bookmarks break?**  
A: No during Phases 1-3. Phase 4 (removal) will break old bookmarks, but with helpful 404 message.

**Q: Can I hide topics I don't want to see?**  
A: Yes — topic filters in feed. "Mute" topics (not yet implemented) via kind 10000 or future UX.

**Q: What about private topics?**  
A: Topics are always public (Nostr event tags). For private curation, use reading lists or magazines.

**Q: Can I still manage topics as an admin?**  
A: Yes — Phase 2 adds admin UI. CLI still works (hidden coordinates, etc.).

**Q: When will this be complete?**  
A: Phase 1 (API) is live now. Phase 2 (integration) estimated for late TBD. Phase 3-4 pending.

---

## Risks & Mitigation

| Risk | Mitigation |
|------|-----------|
| Search latency on articles with many tags | Cache related-articles results; limit to top-6 most-related |
| Topic taxonomy explosion (too many topics) | Filter low-volume topics; curate taxonomy in admin UI |
| Users confused by new topic UI | Parallel rendering (old + new) for 2+ weeks; in-app guidance |
| Breaking bookmarks in Phase 4 | Deprecation notice + redirect with sufficient notice period |
| Topic metadata out-of-sync | Cron job to refresh counts regularly; cache invalidation on article changes |

---

## Success Criteria

- ✅ All forum functionality available via ContentSearchService
- ✅ Related articles section on 100% of article pages
- ✅ Topics searchable and filterable from home feed
- ✅ Forum pages show soft deprecation notice (not hard removal)
- ✅ No regression in search accuracy or performance
- ✅ New UI tested with users; positive feedback on discoverability

---

## Next Steps

1. **Approve this plan** with the team
2. **Start Phase 1 implementation**:
   - Refactor ForumController (1-2 days)
   - Deploy RelatedArticles component (2-3 days)
   - Merge and monitor for issues (ongoing)
3. **Plan Phase 2** kickoff for mid-sprint

---

## Related Documentation

- `documentation/Search/content-search-api.md` — API reference
- `documentation/Search/migration-to-content-search-api.md` — migration guide
- `documentation/Forum/topics.md` — forum taxonomy (legacy)
- `documentation/Reader/advanced-search.md` — search features
- `documentation/Editor/interest-sets.md` — kind 10015 / 30015 details


