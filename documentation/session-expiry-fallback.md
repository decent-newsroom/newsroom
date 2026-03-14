# Session Expiry Fallback Implementation

## Overview
Added fallback mechanisms to handle cases where a user's session expires while editing or broadcasting an article. When the session expires, the system now extracts the user's public key from the event or article data and retrieves relays accordingly.

## Problem
When a user's session expires during article editing, the `getUser()` method returns `null`, which previously caused the system to fail when trying to retrieve the user's relays for publishing articles to Nostr relays.

## Solution

### 1. EditorController - Article Publishing Fallback

**Location:** `src/Controller/Editor/EditorController.php` (lines ~342-395)

**Changes:**
- Added fallback logic to extract pubkey from the signed event when `getUser()` returns null
- When session expires:
  1. Extract `pubkey` from the signed event
  2. Convert hex pubkey to npub format
  3. Look up the User entity in the database by npub
  4. Retrieve stored relays from User entity if available
  5. Fall back to `AuthorRelayService::getRelaysForPublishing()` if no stored relays
  6. Use fallback relays as last resort

**Benefits:**
- Users can continue publishing articles even if their session expires
- No data loss during long editing sessions
- Seamless publishing experience without forcing re-login

**Code Flow:**
```php
if ($user) {
    // Normal flow - use logged-in user's relays
} elseif ($eventPubkeyHex) {
    // Session expired - get relays from event's pubkey
    // 1. Convert pubkey to npub
    // 2. Find User entity in database
    // 3. Get relays from User entity or AuthorRelayService
} else {
    // Last resort - use fallback relays
}
```

### 2. ArticleBroadcastController - Broadcast Fallback

**Location:** `src/Controller/Api/ArticleBroadcastController.php` (lines ~44-92)

**Changes:**
- Reorganized code to fetch article first (before checking user session)
- Added fallback to use article author's relays when session expires
- Removed the hard authentication requirement (401 error) when user is not logged in

**Benefits:**
- Article broadcasts can continue even after session expiry
- Uses the article author's relays (the most relevant relays for the content)
- More resilient broadcasting system

**Code Flow:**
```php
if (empty($relays)) {
    if ($user) {
        // Use logged-in user's relays
    } else {
        // Session expired - use article author's relays
        relays = getRelaysForPublishing($article->getPubkey())
    }
}
```

## Technical Details

### Dependencies
- `swentel\nostr\Key\Key` - For pubkey conversion (hex to bech32)
- `UserEntityRepository` - For looking up User entities by npub
- `AuthorRelayService` - For fetching relays from various sources

### Error Handling
- All relay fetching operations are wrapped in try-catch blocks
- Errors are logged with appropriate context
- Falls back to default relays if any error occurs
- No user-facing errors when fallbacks succeed

### Logging
Added detailed logging to track fallback behavior:
- When session expires and fallback is used
- When relays are retrieved from event pubkey
- When fallback relays are used as last resort

## Testing Considerations

### Manual Testing
1. **Session Expiry During Editing:**
   - Open article editor
   - Edit article for extended period (let session expire)
   - Attempt to publish
   - Verify successful publication with appropriate relays

2. **Session Expiry During Broadcast:**
   - Load article broadcast interface
   - Let session expire
   - Attempt to broadcast article
   - Verify broadcast succeeds using article author's relays

### Edge Cases Handled
- Session expires mid-edit
- No stored relays in User entity
- Event missing pubkey field
- Article author has no relays configured
- Network errors during relay fetching

## Related Files
- `src/Controller/Editor/EditorController.php`
- `src/Controller/Api/ArticleBroadcastController.php`
- `src/Service/AuthorRelayService.php`
- `src/Repository/UserEntityRepository.php`

## Future Improvements
- Consider implementing token refresh to maintain longer sessions
- Add frontend notification when session is about to expire
- Cache relay data in browser localStorage as additional fallback
- Implement automatic session renewal during active editing

## Date
February 11, 2026

