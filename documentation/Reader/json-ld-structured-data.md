# JSON-LD Structured Data Implementation

## Overview

JSON-LD (JavaScript Object Notation for Linked Data) structured data has been implemented across all major pages to improve SEO, crawler/bot/agent friendliness, and discoverability.

## Benefits

- **Enhanced SEO**: Search engines can better understand and index your content
- **Rich Snippets**: Potential for enhanced search results with rich cards
- **AI/Agent Friendly**: LLM-based agents and crawlers can easily parse content structure
- **Knowledge Graph**: Helps search engines build knowledge graphs about your content
- **Social Media**: Better preview cards when shared on social platforms

## Implementation Details

### Base Template (`base.html.twig`)

**Schema Type**: `WebSite`

Default schema for all pages that can be overridden. Includes:
- Site name and description
- Search action with URL template
- Publisher information

### Article Pages (`pages/article.html.twig`)

**Schema Type**: `NewsArticle`

Includes:
- Headline, description, image
- Author information (Person schema)
- Publication dates (published, created, modified)
- Article topics/keywords
- Publisher information
- Free accessibility status

### Author Profile (`profile/author-unified.html.twig`)

**Schema Type**: `ProfilePage` with `Person` entity

Includes:
- Author name, image, description
- Nostr identifier (npub)
- NIP-05 email (if available)
- Website/social links
- Profile URL

### Magazine Pages (`magazine/magazine-front.html.twig`)

**Schema Type**: `Periodical`

Includes:
- Magazine title, description, image
- Language information
- Publisher details
- Magazine URL

### Category Pages (`pages/category.html.twig`)

**Schema Type**: `CollectionPage` with `ItemList`

Includes:
- Category information
- List of articles in the category
- Each article as a `ListItem` with NewsArticle details

### Reading Lists (`pages/list.html.twig`)

**Schema Type**: `ItemList`

Includes:
- List title and description
- List author
- Ordered items with position
- Each article with full metadata

### Discovery Pages

#### Newsstand (`pages/newsstand.html.twig`)
**Schema Type**: `CollectionPage`

Browse magazines collection with metadata about digital magazines.

#### Discover (`pages/discover.html.twig`)
**Schema Type**: `CollectionPage` with `SearchAction`

Includes search functionality definition for crawlers.

#### Latest Articles (`pages/latest-articles.html.twig`)
**Schema Type**: `CollectionPage` with `ItemList`

Lists most recent articles with full metadata.

#### Topics (`pages/topics.html.twig`)
**Schema Type**: `CollectionPage` with optional `ItemList`

Topic browsing with articles filtered by topic.

#### Highlights (`pages/highlights.html.twig`)
**Schema Type**: `CollectionPage`

Community-highlighted passages collection.

#### Media Discovery (`pages/media-discovery.html.twig`)
**Schema Type**: `CollectionPage`

Multimedia content discovery page.

### Search Page (`pages/search.html.twig`)

**Schema Type**: `SearchResultsPage` with `SearchAction`

Includes:
- Search query (if present)
- Search action definition
- URL template for search functionality

### Home Page (`home.html.twig`)

**Schema Type**: `WebSite` (enhanced)

More detailed than base template:
- Multiple potential actions (Search, Browse)
- About topics (Decentralized Publishing, Nostr, Digital Magazines)
- Enhanced description

## Schema.org Types Used

1. **WebSite** - Main site schema
2. **NewsArticle** - Individual articles
3. **Person** - Authors and users
4. **ProfilePage** - Author profiles
5. **Periodical** - Magazines
6. **CollectionPage** - Category and discovery pages
7. **ItemList** - Lists of articles
8. **ListItem** - Individual items in lists
9. **SearchResultsPage** - Search results
10. **SearchAction** - Search functionality
11. **Organization** - Publisher information
12. **Thing** - Topics and concepts

## Validation

You can validate the JSON-LD implementation using:

1. **Google Rich Results Test**: https://search.google.com/test/rich-results
2. **Schema.org Validator**: https://validator.schema.org/
3. **Google Search Console**: Monitor structured data in your property

## Testing

To test the implementation:

```bash
# View page source and look for <script type="application/ld+json">
# Or use browser dev tools to inspect the JSON-LD blocks
```

## Best Practices Followed

1. **Valid JSON**: All JSON-LD is properly escaped using Twig's `json_encode|raw` filter
2. **Hierarchical Structure**: Pages properly reference parent structures (isPartOf)
3. **Required Properties**: All required Schema.org properties are included
4. **Optional Enhancements**: Added optional properties where data is available
5. **Context**: Always includes @context and @type
6. **URLs**: Uses absolute URLs for better portability
7. **Accessibility**: Marked content as `isAccessibleForFree: true`

## Future Enhancements

Potential improvements:
- Add `dateModified` tracking for articles
- Include view counts and engagement metrics
- Add `Review` schema for article comments
- Include `BreadcrumbList` for navigation
- Add `VideoObject` schema for video content
- Include `ImageObject` schema for media
- Add `FAQPage` schema for Q&A sections
- Implement `Event` schema if applicable

## Maintenance

When adding new pages or content types:
1. Choose appropriate Schema.org type
2. Add JSON-LD in the `ogtags` block
3. Include required properties
4. Test with validators
5. Document in this file

## References

- Schema.org Documentation: https://schema.org/
- Google Structured Data Guide: https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data
- JSON-LD Specification: https://json-ld.org/

