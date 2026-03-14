# Magazine Wizard: Attach Existing Lists Feature

## Overview
The magazine wizard has been enhanced to allow users to attach existing reading lists (kind 30040 events) as categories in a magazine, instead of only being able to create new categories from scratch.

## Changes Made

### 1. DTO Updates
**File**: `src/Dto/CategoryDraft.php`
- Added `existingListCoordinate` property to store the coordinate of an existing list (format: `30040:pubkey:slug`)
- Added `isExistingList()` helper method to check if a category references an existing list

### 2. Form Updates
**File**: `src/Form/CategoryType.php`
- Added `existingListCoordinate` field at the top of the form
- Updated help text to guide users on using existing lists vs creating new categories
- Added form validation:
  - Either `existingListCoordinate` OR `title` must be provided
  - If coordinate is provided, validates format (must be `30040:pubkey:slug`)

### 3. Controller Updates
**File**: `src/Controller/Newsroom/MagazineWizardController.php`

#### Setup Method
- Processes categories: if `existingListCoordinate` is provided, loads metadata from the database
- For new categories, generates slugs as before
- Added `loadExistingListMetadata()` helper method to fetch list data by coordinate

#### Articles Method
- Filters out categories that reference existing lists (they already have articles)
- Only shows article editing form for new categories
- If all categories are existing lists, skips directly to review
- Properly merges edited categories back into the draft

#### Review Method
- Skips creating new events for existing lists
- For existing lists, uses the coordinate directly in the magazine's 'a' tags
- For new categories, builds event and generates coordinate as before
- Distinguishes between new and existing in the review display

### 4. Template Updates

#### `templates/magazine/magazine_setup.html.twig`
- Added informational alert explaining the feature
- Links to reading list index where users can find coordinates

#### `templates/magazine/magazine_articles.html.twig`
- Shows info message when some categories use existing lists
- Only displays article editing for new categories

#### `templates/magazine/magazine_review.html.twig`
- Displays badges to distinguish "New Category" vs "Existing List"
- Shows coordinate for existing lists in review

#### `templates/reading_list/index.html.twig`
- Added coordinate display for each reading list
- Added copy-to-clipboard button for coordinates
- Makes it easy for users to grab coordinates when creating magazines

## User Workflow

### Creating a Magazine with Existing Lists

1. **Navigate to Magazine Setup** (`/magazine/wizard/setup`)
2. **Add a Category**:
   - **Option A**: Fill in the "Use existing list" field with a coordinate (e.g., `30040:abc123...:my-list`)
   - **Option B**: Leave it empty and fill in "Category title" to create a new category
3. **Find Coordinates**: 
   - Visit "Your Reading Lists" (`/reading-list`)
   - Each list shows its coordinate with a copy button
4. **Article Attachment**:
   - Existing lists skip this step (they already have articles)
   - New categories show the article form
5. **Review & Publish**:
   - See both new and existing categories
   - Existing lists show their coordinate reference
   - New categories will create new events

## Technical Details

### Event Structure
- **Existing Lists**: Magazine references them via 'a' tag with their coordinate
- **New Categories**: Magazine wizard creates new 30040 events and references them
- Both appear identically in the final magazine structure

### Metadata Loading
When an existing coordinate is provided, the system:
1. Parses the coordinate to extract pubkey and slug
2. Queries the database for the matching 30040 event
3. Loads title, summary, image, tags, and article coordinates
4. Populates the CategoryDraft with this data (read-only in the wizard)

### Validation
- Coordinate format must be exactly `30040:pubkey:slug`
- Either coordinate OR title must be provided (not both required)
- Invalid coordinates show clear error messages

## Benefits

1. **Reusability**: Users can reuse their existing reading lists across multiple magazines
2. **Efficiency**: No need to recreate categories or re-enter article coordinates
3. **Consistency**: Same list can appear in multiple magazines, maintaining single source of truth
4. **Flexibility**: Mix and match - some categories can be existing lists, others newly created
5. **User-Friendly**: Clear UI guidance with coordinate display and copy buttons

## Future Enhancements

Potential improvements:
- Auto-complete dropdown for selecting user's existing lists (instead of manual coordinate entry)
- Visual preview of existing list contents before attaching
- Ability to edit existing list metadata when attaching (create a derivative)
- Bulk attach multiple existing lists at once
