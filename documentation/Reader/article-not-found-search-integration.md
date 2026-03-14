# Article Not Found Search Integration

## Overview
This document describes the implementation of showing a search component instead of 404 error pages when articles are not found.

## Changes Made

### 1. Created New Template: `templates/pages/article_not_found.html.twig`
A new user-friendly template that:
- Displays a clear "Article Not Found" message
- Shows the specific error message for context
- Includes an embedded SearchComponent for immediate searching
- Provides helpful instructions to paste Nostr addresses (naddr) for article discovery
- Includes styling for a clean, professional appearance

### 2. Updated ArticleController.php
Replaced all `throw $this->createNotFoundException()` and `throw new \Exception()` calls with renders of the new template:

#### Methods Updated:
- **resolveVanityOrRedirect()**: Returns article_not_found template instead of throwing exceptions for missing profiles
- **naddr()**: Handles invalid naddr, non-longform articles, and fetch failures gracefully
- **draftSlug()**: Shows helpful message when draft is not found or user lacks permission
- **disambiguation()**: Displays search when no articles found for a slug
- **authorDraft()**: Renders article_not_found when draft doesn't exist
- **authorArticle()**: Shows search component when article is not in database

### 3. Key Features

#### Search Integration
- The SearchComponent is embedded directly in the error page
- Pre-populates the search query with the attempted search term (slug, naddr, etc.)
- Allows users to immediately search for alternatives or paste a Nostr address

#### Helpful Error Messages
Each error scenario provides specific, actionable information:
- Invalid naddr format
- Non-longform article types
- Missing articles by specific authors
- Relay connection issues
- Permission problems for drafts

#### User Experience Improvements
- No harsh 404 errors
- Immediate path to resolution (search or paste naddr)
- Clear instructions about what went wrong
- Consistent styling with the rest of the application

## Usage

When an article cannot be found, users will see:
1. A clear heading: "Article Not Found"
2. A specific error message explaining what happened
3. An information box encouraging them to paste a Nostr address (naddr)
4. The SearchComponent ready to use with their query pre-filled
5. A button to return to the homepage

## Benefits

1. **Better UX**: Users aren't met with dead-ends
2. **Discovery**: Failed searches become opportunities to explore
3. **Nostr Integration**: Users can fetch articles directly from the Nostr network by pasting naddr
4. **Reduced Bounce Rate**: Keep users engaged even when initial request fails
5. **Clear Communication**: Specific error messages help users understand what went wrong

## Technical Notes

- The template extends `layout.html.twig` for consistent styling
- Uses Twig component syntax: `<twig:SearchComponent :query="searchQuery" />`
- All error handling now returns Response objects instead of throwing exceptions
- The SearchComponent handles Nostr identifier detection and routing automatically

## Future Enhancements

Potential improvements:
- Add analytics to track common not-found queries
- Suggest related articles based on the failed search
- Implement automatic retry with alternative relays for naddr fetches
- Show recently viewed or popular articles as fallback suggestions

