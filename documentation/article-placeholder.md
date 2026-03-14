# Article Placeholder Implementation

## Overview
This implementation provides a visual placeholder for articles that haven't been fully loaded yet in card lists. It makes it clear to users that there are additional items in the list, even if they're not immediately available.

## Components Created

### 1. CardPlaceholder Component
**Files:**
- `templates/components/Molecules/CardPlaceholder.html.twig` - Template for the placeholder card
- `src/Twig/Components/Molecules/CardPlaceholder.php` - PHP component class

**Features:**
- Shows a clear indicator that an article is part of the list but not fully loaded
- Displays article metadata when available (ID, pubkey, slug)
- Provides a "Fetch Article" button to trigger loading
- Uses Stimulus controller for interactive functionality

### 2. Styling
**File:** `assets/styles/03-components/card-placeholder.css`

**Features:**
- Dashed border to differentiate from regular cards
- Hover effects for better UX
- Dark mode support
- Responsive design
- Loading state animations

### 3. JavaScript Controller
**File:** `assets/controllers/ui/card-placeholder_controller.js`

**Features:**
- Handles fetch button clicks
- Shows loading states with spinning icon
- Displays success/error feedback
- Reloads page after successful fetch (can be customized)

## Usage

The placeholder is automatically used in `CardList` when an article doesn't have both a `slug` and `title`:

```twig
{% for item in list %}
    {% if item.slug is not empty and item.title is not empty %}
        <twig:Molecules:Card :article="item" />
    {% else %}
        <twig:Molecules:CardPlaceholder :item="item" />
    {% endif %}
{% endfor %}
```

## Integration Notes

### API Endpoint
The JavaScript controller calls `/api/fetch-article` with the following payload:
```json
{
    "id": "article-id",
    "pubkey": "pubkey",
    "slug": "article-slug",
    "coordinate": "30023:pubkey:slug"
}
```

**The API endpoint is fully implemented** and handles article fetching in the following way:

1. **Check Database First**: Looks for the article in the local database
2. **Fetch from Nostr**: If not found locally, uses `NostrClient::getArticlesByCoordinates()` to fetch from Nostr relays
3. **Save to Database**: Uses `ArticleEventProjector::projectArticleFromEvent()` to persist the fetched article
4. **Return Response**: Returns success with article details or appropriate error message

### API Implementation
```php
// src/Controller/Api/ArticleFetchController.php
#[Route('/api/fetch-article', methods: ['POST'])]
public function fetchArticle(Request $request): JsonResponse
{
    // 1. Parse coordinate from request
    $coordinate = $data['coordinate'] ?? "30023:{$pubkey}:{$slug}";
    
    // 2. Check if already in database
    $existingArticle = $this->articleRepository->find(...);
    if ($existingArticle) {
        return success response;
    }
    
    // 3. Fetch from Nostr relays
    $articlesMap = $this->nostrClient->getArticlesByCoordinates([$coordinate]);
    
    // 4. Save to database
    $event = $articlesMap[$coordinate];
    $this->articleProjector->projectArticleFromEvent($event, 'api-fetch');
    
    return success response;
}
```

### How Nostr Fetching Works

The implementation uses the sophisticated `NostrClient` service which:
1. Gets the author's preferred relays (from their relay list)
2. Falls back to reputable public relays if needed
3. Creates optimized relay connections using the relay pool
4. Sends REQ messages with proper filters (kind, author, #d tag for slug)
5. Returns the most recent matching event

The `ArticleEventProjector` then:
1. Converts the Nostr event to an Article entity using `ArticleFactory`
2. Processes markdown content to HTML for performance
3. Persists to database
4. Triggers async profile fetching for the author

## Customization

### Change Loading Behavior
To update the card in-place instead of reloading the page, modify the success handler in `card-placeholder_controller.js`:

```javascript
// Instead of:
setTimeout(() => {
    window.location.reload();
}, 1000);

// Use Turbo Streams or replace the placeholder element:
this.element.outerHTML = data.cardHtml;
```

### Customize Appearance
Edit `assets/styles/03-components/card-placeholder.css` to change colors, spacing, or add animations.

### Add Loading States
The placeholder already shows loading states on the button, but you can add a loading overlay:

```css
.card-placeholder.loading {
    opacity: 0.6;
    pointer-events: none;
}
```

## Testing

To test the placeholder:
1. Create a CardList with items that have missing `slug` or `title`
2. The placeholder should appear for incomplete items
3. Click "Fetch Article" to trigger the fetch action
4. Verify the loading state appears
5. Check browser console for API calls

## Future Enhancements

Potential improvements:
- Auto-retry failed fetches
- Queue multiple fetches
- Progress indicators for multiple placeholders
- Background fetching without user action
- Cache fetched articles to avoid re-fetching
