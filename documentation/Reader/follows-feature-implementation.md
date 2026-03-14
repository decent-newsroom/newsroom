# Following Feature Implementation

## Overview
Implemented a new `/follows` route that displays articles from users in the logged-in user's follow list.

## Files Created

### 1. Controller: `src/Controller/FollowsController.php`
- **Route**: `/follows` (name: `follows`)
- **Functionality**:
  - Checks if user is logged in
  - If not logged in: Shows a notice prompting user to sign in
  - If logged in:
    - Retrieves user's follow list from Nostr (kind 3 events)
    - Extracts followed pubkeys from 'p' tags
    - Queries articles from followed authors
    - Fetches author metadata (kind 0 events) for display
    - Returns up to 50 latest articles ordered by creation date
  - Handles errors gracefully with appropriate error messages

### 2. Template: `templates/follows/index.html.twig`
- Extends the standard layout
- Shows different views based on user state:
  - **Not logged in**: Alert with sign-in prompt
  - **No follows**: Info message explaining how to use the feature
  - **No articles**: Info message when followed users haven't published
  - **With articles**: Displays articles using the CardList component
- Uses existing components:
  - `Organisms:CardList` for article display
  - `Atoms:FeaturedWriters` in sidebar
  - `Atoms:ForumAside` in sidebar

### 3. Navigation Update: `templates/components/UserMenu.html.twig`
- Added "Following" link to the user menu for logged-in users
- Positioned after admin-only NZines link and before "Compose List"

## How It Works

1. **Follow List Retrieval**:
   - Queries the `Event` table for kind 3 (FOLLOWS) events for the current user
   - Takes the most recent follow list event
   - Extracts all pubkeys from 'p' tags in the event

2. **Article Fetching**:
   - Uses Doctrine QueryBuilder to fetch articles where `pubkey` is in the follow list
   - Orders by `createdAt` DESC
   - Limits to 50 most recent articles

3. **Author Metadata**:
   - For each unique author, fetches their kind 0 (METADATA) event
   - Decodes JSON metadata to display author information
   - Falls back to truncated pubkey if metadata is unavailable

## User Experience

- **Logged-in users**: See latest articles from people they follow on Nostr
- **Logged-out users**: See a prompt to sign in
- **Users with no follows**: See a helpful message explaining the feature
- **Navigation**: Easy access via user menu in the sidebar

## Technical Notes

- Uses existing Nostr event types (KindsEnum::FOLLOWS and KindsEnum::METADATA)
- Follows Symfony best practices with attribute routing
- Integrates seamlessly with existing template structure
- Handles edge cases (no metadata, invalid data, etc.)
- Uses Doctrine ORM for efficient database queries

## Testing Recommendations

1. Test as logged-out user - should see sign-in prompt
2. Test as logged-in user with no follows - should see helpful message
3. Test as logged-in user with follows but no articles - should see appropriate message
4. Test as logged-in user with follows and articles - should see article list
5. Verify navigation link appears correctly in user menu
6. Test with various follow list sizes to ensure performance

## Future Enhancements (Optional)

- Add pagination for users following many active writers
- Add filtering options (by date, topic, etc.)
- Cache follow lists for better performance
- Add ability to refresh/sync follow list from relays
- Show statistics (number of new articles since last visit)

