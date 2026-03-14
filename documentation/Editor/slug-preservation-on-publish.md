# Slug Preservation on Publish Feature

## Summary
When publishing or saving a draft in the editor, if no slug is present in the form, a slug is automatically generated. This slug is now inserted back into the form input field so it's preserved if something goes wrong during publishing and the user needs to retry.

## Problem
Previously, when a user clicked "Save Draft" or "Publish" without entering a slug:
1. The JavaScript would generate a slug from the title
2. This slug would be included in the Nostr event
3. The event would be published
4. But if publishing failed or something went wrong, the generated slug was lost
5. On retry, a new slug would be generated (potentially different)

## Solution
When collecting form data for publishing, if no slug is present in the form field, a slug is automatically generated from the title. The frontend JavaScript now:
1. Generates the slug during form data collection (before publishing)
2. Immediately updates the slug input field with the generated slug (only if the field is currently empty)
3. This preserves the slug for any subsequent retry attempts, even if the publish fails

## Changes Made

### Backend (already working)
The `EditorController::publishNostrEvent()` method already returns the slug in the JSON response:

```php
return new JsonResponse([
    'success' => true,
    'message' => $isDraft ? 'Draft saved successfully' : 'Article published successfully',
    'articleId' => $article->getId(),
    'slug' => $article->getSlug(),  // ← Slug is returned here
    'isDraft' => $isDraft,
    'redirectUrl' => $redirectUrl,
    'relayResults' => $relayResults
]);
```

### Frontend

#### 1. `assets/controllers/nostr/nostr_publish_controller.js`
Added code in `collectFormData()` method to update the slug field immediately when a slug is generated:

```javascript
// Reuse existing slug if provided on the container (editing), else generate from title
const existingSlug = (this.element.dataset.slug || '').trim();
const slug = existingSlug || this.generateSlug(String(title));

// Update slug field with generated slug if it was generated (not from container dataset)
if (!existingSlug && slug) {
    const slugInput = document.querySelector('input[name="editor[slug]"]');
    if (slugInput && !slugInput.value) {
        slugInput.value = slug;
        console.log('[nostr-publish] Updated slug field with generated slug:', slug);
    }
}
```

#### 2. `assets/controllers/nostr/nostr_single_sign_controller.js`
Added a fallback in `signAndPublish()` method to update slug from backend response (though it should already be set by the time this runs):

```javascript
// Fallback: Update slug field if somehow it's still empty
// (should already be set by nostr_publish_controller's collectFormData)
if (result.slug) {
    const slugInput = document.querySelector('input[name="editor[slug]"]');
    if (slugInput && !slugInput.value) {
        slugInput.value = result.slug;
        console.log('[nostr_single_sign] Updated slug field with slug from backend:', result.slug);
    }
}
```

## How It Works

### User Flow
1. User writes an article without entering a custom slug
2. User clicks "Save Draft" or "Publish"
3. **JavaScript generates a slug from the title and immediately inserts it into the form field** (e.g., `my-article-a1b2c3`)
4. Event is signed and published with that slug
5. Backend saves the article with the slug
6. Backend returns success response with the same slug
7. If user needs to retry publishing (e.g., network error), the same slug will be used

### Technical Details
- The slug field is updated in `collectFormData()` method before the event is signed
- The slug field is only updated if it's currently empty (`!slugInput.value`)
- This prevents overwriting a custom slug the user may have entered
- Works for both extension-based signing and remote signer (Amber/Bunker)
- The slug is logged to console for debugging
- A fallback update also happens after backend response in the remote signer flow

## Testing

To test this feature:
1. Open the editor
2. Write an article title but leave the slug field empty
3. Click "Save Draft" or "Publish"
4. **Immediately after clicking, check that the slug field contains the generated slug** (before the publish completes)
5. If you cancel or the publish fails, the slug is still there
6. If you retry publishing, the same slug will be used

## Files Changed
- `assets/controllers/nostr/nostr_publish_controller.js`
- `assets/controllers/nostr/nostr_single_sign_controller.js`





