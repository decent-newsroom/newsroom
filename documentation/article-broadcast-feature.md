# Article Broadcast Feature

## Overview
The article broadcast feature allows you to send existing articles from your database to Nostr relays on demand, without any modifications. This is the opposite of the fetch feature - instead of pulling articles from relays, you're pushing them out.

## Use Cases

1. **Re-broadcast after relay downtime** - Relay was down when article was published, now broadcast it
2. **Broadcast to new relays** - Author added new relays to their list, broadcast existing articles
3. **Ensure availability** - Make sure article is on multiple relays for redundancy
4. **Recovery** - Article was deleted from some relays, re-broadcast it
5. **Migration** - Moving to new relay infrastructure, broadcast all articles

## Components

### 1. API Endpoint
**File**: `src/Controller/Api/ArticleBroadcastController.php`

**Route**: `POST /api/broadcast-article`

**Request Body**:
```json
{
  "article_id": 123,              // Database ID (optional if coordinate provided)
  "coordinate": "30023:pubkey:slug",  // Nostr coordinate (optional if article_id provided)
  "relays": [                     // Optional: specific relays to broadcast to
    "wss://relay1.com",
    "wss://relay2.com"
  ]
}
```

**Response**:
```json
{
  "success": true,
  "message": "Article broadcast to relays",
  "article": {
    "id": 123,
    "event_id": "abc123...",
    "title": "My Article",
    "slug": "my-article",
    "pubkey": "..."
  },
  "broadcast": {
    "total_relays": 5,
    "successful": 4,
    "failed": 1,
    "failed_relays": [
      {
        "relay": "wss://down-relay.com",
        "error": "Connection timeout"
      }
    ]
  }
}
```

### 2. JavaScript Controller
**File**: `assets/controllers/ui/article_broadcast_controller.js`

**Stimulus Controller**: `ui--article-broadcast`

**Values**:
- `articleIdValue` - Database ID of article
- `coordinateValue` - Nostr coordinate (kind:pubkey:slug)

**Actions**:
- `broadcast` - Triggers the broadcast

### 3. Twig Component
**Template**: `templates/components/Molecules/BroadcastButton.html.twig`
**Component**: `src/Twig/Components/Molecules/BroadcastButton.php`

**Usage**:
```twig
<twig:Molecules:BroadcastButton :article="article" />
```

## How It Works

### Flow Diagram
```
1. User clicks "Broadcast to Relays" button
   ↓
2. JavaScript controller captures click
   ↓
3. POST /api/broadcast-article with article ID/coordinate
   ↓
4. API finds article in database
   ↓
5. Extracts raw event data (stored in article.raw field)
   ↓
6. Reconstructs swentel\nostr\Event\Event object
   ↓
7. Calls NostrClient::publishEvent()
   ↓
8. NostrClient determines target relays:
   - If relays provided: Use those + ensure local relay
   - If empty: Auto-fetch author's preferred relays
   ↓
9. Publishes EVENT message to all relays
   ↓
10. Collects results from each relay
   ↓
11. Returns summary (success/failed per relay)
   ↓
12. UI shows result: "Broadcast! (4/5 relays)"
```

### Relay Selection

**Option 1: Explicit Relays**
```json
{
  "article_id": 123,
  "relays": ["wss://relay1.com", "wss://relay2.com"]
}
```
- Broadcasts to specified relays
- Local relay is automatically added if configured

**Option 2: Auto-Select (Default)**
```json
{
  "article_id": 123
  // relays omitted or empty array
}
```
- Fetches author's relay list (NIP-65)
- Uses top reputable relays from author's list
- Falls back to default relays if author list unavailable

### Event Reconstruction

The article's `raw` field contains the original Nostr event as JSON:
```json
{
  "id": "event_id_hash",
  "kind": 30023,
  "pubkey": "author_pubkey_hex",
  "created_at": 1234567890,
  "content": "# Article content...",
  "tags": [
    ["d", "article-slug"],
    ["title", "Article Title"],
    ["summary", "Article summary"],
    ["image", "https://..."],
    ["published_at", "1234567890"]
  ],
  "sig": "signature_hex"
}
```

The broadcast endpoint:
1. Gets this raw data
2. Creates a new `Event` object
3. Sets all fields identically (NO modifications)
4. Broadcasts the exact same event to relays

## Implementation Details

### ArticleBroadcastController

**Key Methods**:

```php
public function broadcastArticle(Request $request): JsonResponse
{
    // 1. Parse request (article_id or coordinate)
    // 2. Find article in database
    // 3. Get raw event data
    // 4. Reconstruct Event object
    // 5. Call NostrClient::publishEvent()
    // 6. Count successes/failures
    // 7. Return detailed results
}
```

**Features**:
- ✅ Accepts article ID or coordinate
- ✅ Validates article exists
- ✅ Checks for raw event data
- ✅ Reconstructs exact event (no modifications)
- ✅ Detailed logging
- ✅ Per-relay success/failure tracking
- ✅ Error handling with meaningful messages

### NostrClient::publishEvent()

The broadcast uses the existing `publishEvent()` method:

```php
public function publishEvent(Event $event, array $relays): array
{
    // If no relays, auto-fetch author's relays
    if (empty($relays)) {
        $relays = $this->getTopReputableRelaysForAuthor($pubkey);
    } else {
        // Ensure local relay is included
        $relays = $this->relayPool->ensureLocalRelayInList($relays);
    }
    
    // Create relay set using connection pool
    $relaySet = $this->createRelaySet($relays);
    $relaySet->setMessage(new EventMessage($event));
    
    // Send to all relays and return results
    return $relaySet->send();
}
```

**Features**:
- Connection pooling (reuses WebSocket connections)
- Automatic relay selection if not specified
- Local relay inclusion for caching
- Per-relay result tracking
- Exception handling per relay (one failure doesn't stop others)

## Security & Integrity

### No Modifications
The broadcast feature sends the **exact** event as stored:
- Same event ID (hash)
- Same signature
- Same content
- Same tags
- Same timestamps

This ensures:
- ✅ Signature remains valid
- ✅ Event ID matches content
- ✅ No tampering possible
- ✅ Relays can verify authenticity

### Validation
Articles without raw event data cannot be broadcast:
```json
{
  "success": false,
  "error": "Article does not have raw event data"
}
```

This prevents broadcasting malformed or incomplete events.

## Usage Examples

### Example 1: Broadcast Specific Article
```javascript
// Using article ID
fetch('/api/broadcast-article', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ article_id: 123 })
});
```

### Example 2: Broadcast to Specific Relays
```javascript
fetch('/api/broadcast-article', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        article_id: 123,
        relays: [
            'wss://relay.damus.io',
            'wss://nos.lol',
            'wss://relay.snort.social'
        ]
    })
});
```

### Example 3: Using Coordinate
```javascript
fetch('/api/broadcast-article', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        coordinate: '30023:abc123def456...:my-article-slug'
    })
});
```

### Example 4: Using Twig Component
```twig
{# In article view template #}
<div class="article-actions">
    <twig:Molecules:BroadcastButton :article="article" />
</div>
```

### Example 5: Batch Broadcast
```php
// Controller action to broadcast multiple articles
foreach ($articles as $article) {
    $this->nostrClient->publishEvent(
        $this->reconstructEvent($article->getRaw()),
        [] // Auto-select relays
    );
}
```

## UI Integration

### Button States

**Default State**:
```html
📡 Broadcast to Relays
```

**Loading State**:
```html
⟳ Broadcasting...
```

**Success State**:
```html
✓ Broadcast! (4/5 relays)
```

**Error State**:
```html
⚠ Failed - Try Again
```

### Where to Add Broadcast Button

1. **Article view page** - Let readers broadcast articles they like
2. **Author dashboard** - Broadcast own articles to new relays
3. **Admin panel** - Mass re-broadcast for maintenance
4. **Reading lists** - Broadcast articles in curated lists
5. **Search results** - Quick broadcast from search

Example placement:
```twig
{# templates/article/view.html.twig #}
<article>
    <h1>{{ article.title }}</h1>
    
    <div class="article-actions">
        {# Other actions: share, bookmark, etc. #}
        <twig:Molecules:BroadcastButton :article="article" />
    </div>
    
    <div class="content">
        {{ article.processedHtml|raw }}
    </div>
</article>
```

## Logging

The broadcast feature logs at multiple levels:

**Info Level**:
```
[info] Broadcasting article to relays {
    "article_id": 123,
    "event_id": "abc...",
    "title": "My Article",
    "pubkey": "def456...",
    "relay_count": 5
}

[info] Article broadcast completed {
    "article_id": 123,
    "event_id": "abc...",
    "total_relays": 5,
    "successful": 4,
    "failed": 1
}
```

**Error Level**:
```
[error] Failed to broadcast article {
    "article_id": 123,
    "error": "Network timeout",
    "trace": "..."
}
```

## Monitoring

Key metrics to track:
- **Broadcast success rate** - % of successful relay broadcasts
- **Failed relays** - Which relays fail most often
- **Broadcast latency** - Time to broadcast to all relays
- **Usage patterns** - Which articles are broadcast most
- **Relay performance** - Which relays respond fastest

## Testing

### Manual Test
```bash
# 1. Find an article in your database
# 2. Use curl to broadcast it
curl -X POST http://localhost:8000/api/broadcast-article \
  -H "Content-Type: application/json" \
  -d '{"article_id": 123}'

# 3. Check response for success/failures
# 4. Verify event appears on relays
```

### UI Test
1. Navigate to an article page
2. Click "Broadcast to Relays" button
3. Watch button change to "Broadcasting..."
4. Button should show "✓ Broadcast! (X/Y relays)"
5. Check browser console for details
6. Check server logs for detailed info

### Relay Verification
```bash
# Connect to relay and check for event
wscat -c wss://relay.damus.io

# Send REQ for the article
["REQ","test",{"kinds":[30023],"authors":["pubkey"],"#d":["slug"]}]

# Should receive EVENT message with the article
```

## Troubleshooting

### "Article not found"
- Check article ID is correct
- Verify coordinate format: `kind:pubkey:slug`
- Ensure article exists in database

### "Article does not have raw event data"
- Article was created locally, not ingested from Nostr
- Article needs to be properly signed and have raw field populated
- Consider using editor to re-publish with raw data

### All relays failed
- Check network connectivity
- Verify relay URLs are correct
- Check if relays are online (use relay checker tool)
- Review server logs for specific errors

### Some relays failed
- Normal - some relays may be temporarily down
- Check failed_relays in response for details
- Retry later for failed relays

## Future Enhancements

1. **Scheduled broadcasts** - Cron job to re-broadcast periodically
2. **Selective relay targeting** - UI to choose specific relays
3. **Batch operations** - Broadcast multiple articles at once
4. **Broadcast history** - Track when/where articles were broadcast
5. **Relay health monitoring** - Avoid broadcasting to down relays
6. **Retry mechanism** - Auto-retry failed broadcasts
7. **Webhook notifications** - Alert when broadcast completes

## Related Features

- **Article Fetch** (`ArticleFetchController`) - Pull articles from relays
- **Article Publish** (Editor) - Create new articles and publish
- **Relay Management** - Manage relay lists
- **Event Ingestion** - Process incoming events from relays

---

**Status**: ✅ COMPLETE - Fully implemented and documented
**Date**: January 28, 2026
