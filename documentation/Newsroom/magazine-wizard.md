# Magazine Wizard Upgrade

## Overview

The magazine wizard has been upgraded from a 2-step flow into a 6-step flow with improved UX for each step. Magazine creation is free; subdomain hosting is a paid service (120,000 sats/year via Lightning).

## Steps

### Step 1: Magazine Setup (`/magazine/wizard/setup`)
- **What it does:** Captures magazine metadata — title, summary, cover image, language, and tags.
- **Live preview:** A real-time preview card appears alongside the form, updating as you type. The preview mirrors the magazine's cover/hero layout.
- **Image upload:** The cover image field supports media upload via the existing `publishing--image-upload` Stimulus controller with NIP-98 authentication (same as the editor and reading lists).
- **Login prompt:** Users not logged in see a prompt to authenticate before proceeding.
- **Desktop hint:** On mobile viewports, a banner suggests using a desktop device for the best experience.
- **Controller:** `ui--magazine-preview` (Stimulus) provides the live preview updates.

### Step 2: Categories (`/magazine/wizard/categories`)
- **What it does:** Manages the magazine's category structure — add, remove, reorder, and configure categories.
- **Dropdown selector:** Instead of a raw naddr coordinate input, categories can be selected from a dropdown populated with the user's existing reading lists (via `ReadingListManager::getUserReadingLists()`). Creating a new category is the default option.
- **Toggle UI:** When an existing list is selected, the new-category fields (title, summary, image, tags) are hidden since they come from the existing list. Controller: `ui--category-toggle`.
- **Image upload:** Each category has its own image upload widget.
- **Sortable:** Categories can be reordered via drag-and-drop handles. Controller: `ui--sortable`. Form field indices are re-indexed on reorder to ensure Symfony receives the correct order.
- **Add/Remove:** New categories can be added dynamically; existing ones can be removed. Uses the enhanced `ui--form-collection` controller (now with `removeCollectionElement` action).
- **Form type:** `MagazineCategoriesType` — a new form type dedicated to this step.

### Step 3: Articles (`/magazine/wizard/articles`)
- Unchanged from before. Attaches article coordinates to each new category.
- Categories referencing existing lists are skipped (they already have articles).
- Now includes a "Back" button to return to the categories step.

### Step 4: Review & Sign (`/magazine/wizard/review`)
- Unchanged from before but now includes:
  - Wizard step indicator
  - Login prompt for unauthenticated users
  - "Back" button to return to the articles step

### Step 5: Subdomain (`/magazine/wizard/subdomain`)
- **What it does:** Offers paid subdomain hosting for the just-published magazine.
- **Payment flow:** The form posts directly to the existing `publication_subdomain_create` route, which creates a subscription and generates a Lightning invoice. The user is redirected to the invoice page with a QR code.
- **Magazine coordinate auto-fill:** The magazine coordinate (`30040:pubkey:slug`) is automatically built from the wizard draft's slug and the user's npub, passed as a hidden field.
- **Pricing:** Displays the current price (120,000 sats/year) inline using `PublicationSubdomainSubscription::PRICE_SATS`.
- **Subdomain availability:** Uses the existing `blog--subdomain-check` Stimulus controller to check availability in real time. The submit button is disabled until availability is confirmed.
- **Error handling:** On validation or creation errors, the user is redirected back to the wizard subdomain step (not the standalone subscribe page) via an `_error_redirect` hidden field.
- **Skip option:** Users can skip subdomain setup and proceed directly to the launched/done step.
- **Existing subscription:** If the user already has an active or pending subscription, they see a summary and a link to continue.

### Step 6: Done / Launched (`/magazine/wizard/launched`)
- Congratulations and next-steps page.
- Shows subdomain status (active/pending) if the user set one up.
- Links to "My Magazines" and the newsstand.

## Wizard Progress Indicator

A shared partial template (`_wizard_steps.html.twig`) renders a numbered step indicator at the top of each page, highlighting the current step and marking completed steps.

## Files Changed

### New Files
| File | Purpose |
|------|---------|
| `src/Form/MagazineCategoriesType.php` | Form type for the categories step |
| `templates/magazine/magazine_categories.html.twig` | Categories step template |
| `templates/magazine/_wizard_steps.html.twig` | Reusable wizard progress indicator |
| `assets/controllers/ui/magazine_preview_controller.js` | Live preview Stimulus controller |
| `assets/controllers/ui/sortable_controller.js` | Drag-to-reorder Stimulus controller |
| `assets/controllers/ui/category_toggle_controller.js` | Show/hide new-category fields |
| `assets/styles/04-pages/magazine-wizard.css` | Wizard-specific styles |

### Modified Files
| File | Change |
|------|--------|
| `src/Form/MagazineSetupType.php` | Removed categories collection; added Stimulus data attributes for live preview |
| `src/Form/CategoryType.php` | Replaced raw text input with `ChoiceType` dropdown; added `user_lists` option |
| `src/Controller/Newsroom/MagazineWizardController.php` | Split setup into setup + categories; injected `ReadingListManager`; added `mag_wizard_categories` route |
| `templates/magazine/magazine_setup.html.twig` | Redesigned with preview panel, image upload, login prompt, desktop hint |
| `templates/magazine/magazine_articles.html.twig` | Added wizard steps, back navigation |
| `templates/magazine/magazine_review.html.twig` | Added wizard steps, login prompt, back navigation |
| `assets/controllers/publishing/image_upload_controller.js` | Added `urlInput` target for scoped image field targeting |
| `assets/controllers/ui/form-collection_controller.js` | Added `removeCollectionElement` action |
| `assets/app.js` | Added magazine-wizard.css import |
| `CHANGELOG.md` | Added feature entries |

## Technical Notes

### Image Upload Scoping Fix
The `image_upload_controller.js` previously used `document.querySelector('input[name$="[image]"]')` which only finds the first match globally. This broke when multiple category image fields existed. The fix adds `urlInput` to `static targets` and uses a scoped lookup chain:
1. `this.urlInputTarget` (if explicitly wired)
2. `this.element.closest('form')?.querySelector(...)` (form-scoped)
3. `document.querySelector(...)` (global fallback)

### Sortable Field Re-indexing
When categories are reordered via drag-and-drop, the `sortable_controller.js` updates all `input`, `select`, and `textarea` field names/IDs within each `<li>` to reflect the new sequential index. This ensures Symfony's form system receives categories in the user's intended order.

### Category Dropdown Data
The dropdown is populated from `ReadingListManager::getUserReadingLists()` which returns kind 30040 events (reading lists/categories) owned by the current user, excluding magazine indexes (30040 events that reference other 30040 events).

