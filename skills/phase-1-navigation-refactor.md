# Navigation Refactor Phase 1: Implementation Checklist

This checklist captures the concrete tasks for Phase 1 of the Navigation Refactor: using the new three-layout architecture to modernize the sidebar structure without major route changes.

## Overview

Phase 1 goals:
- Migrate Reading Nook pages to use `reading-nook-layout.html.twig` with local nav
- Migrate Newsroom pages to use `newsroom-layout.html.twig` with local nav
- Keep all existing routes unchanged
- Simplify the main global sidebar (already done)
- Add translation keys for new nav labels

## Infrastructure (Done)
- [x] Create `SidebarNav` Twig component
- [x] Create `NavigationBuilderTrait` helper
- [x] Create `reading-nook-layout.html.twig`
- [x] Create `newsroom-layout.html.twig`
- [x] Simplify main `layout.html.twig`
- [x] Document architecture in `documentation/Newsroom/navigation-layouts-implementation.md`

## Reading Nook Migration

### Migrate ReadingNookController

- [ ] Add `use NavigationBuilderTrait;` to `src/Controller/Reader/ReadingNookController.php`
- [ ] In `index()` method, add `'readingNookNav' => $this->buildReadingNookNav()` to render data
- [ ] Verify `templates/reader/reading_nook/index.html.twig` extends `reading-nook-layout.html.twig`
- [ ] Check that the Reading Nook sidebar appears on `/reading-nook`
- [ ] Test all filter/search functionality still works

### Update ReadingNookController template

- [ ] Update first line of `templates/reader/reading_nook/index.html.twig` to extend `reading-nook-layout.html.twig` instead of `layout.html.twig`
- [ ] Remove any custom sidebar/nav code from the template (now handled by layout)
- [ ] Keep the main content and filtering UI

### Test Reading Nook

- [ ] Visit `/reading-nook` as authenticated user
- [ ] Verify sidebar shows: Overview, Saved (Bookmarks/Interests), Collections (Reading Lists/Follow Packs)
- [ ] Verify "Back to Main Newsroom" link points to correct destination
- [ ] Verify sidebar closes/opens with toggle button
- [ ] Test that filters and search still work
- [ ] Test on mobile (sidebar toggle particularly)

## Newsroom Migration

### Controllers to migrate

These controllers should use `newsroom-layout.html.twig` and `buildNewsroomNav()`:

- `src/Controller/Newsroom/MyContentController.php`
- `src/Controller/Newsroom/ReadingListController.php`
- `src/Controller/Newsroom/MagazineWizardController.php`
- `src/Controller/DefaultController.php` — check if other methods need migration

### Update MyContentController

- [ ] Add `use NavigationBuilderTrait;` to the class
- [ ] In `index()` method, add `'newsroomNav' => $this->buildNewsroomNav()` to render data
- [ ] Verify `templates/my_content/index.html.twig` extends `newsroom-layout.html.twig`

### Update ReadingListController

- [ ] Add `use NavigationBuilderTrait;`
- [ ] In `index()` method (reading list index), add `'newsroomNav' => $this->buildNewsroomNav()`
- [ ] Verify `templates/reading_list/index.html.twig` extends `newsroom-layout.html.twig`
- [ ] Check wizard/compose routes; decide if they also use newsroom-layout or stay on standard layout

### Update MagazineWizardController

- [ ] Add `use NavigationBuilderTrait;`
- [ ] Check which routes should show the sidebar:
  - [ ] `mag_wizard_new` (setup step) — might stay on standard layout during wizard flow
  - [ ] `mag_wizard_articles` — check
  - [ ] `mag_wizard_review` — check
- [ ] Consider: should the magazine wizard stay in "global" mode or switch to "newsroom" mode once user enters it?
- [ ] Document the decision (e.g., wizard is a modal/focused flow, uses standard layout; published magazines show in Newsroom overview)

### Update DefaultController

- [ ] Check methods that should render with newsroom-layout:
  - [ ] `myMagazines()` — migrate to newsroom-layout
  - [ ] `mediaManager()` — migrate to newsroom-layout
  - [ ] Others? Review `src/Controller/DefaultController.php` for auth-required author-specific pages
- [ ] Add trait and nav data where needed

### Update templates to use newsroom-layout

- [ ] `templates/my_content/index.html.twig` — extend `newsroom-layout.html.twig`
- [ ] `templates/reading_list/index.html.twig` — extend `newsroom-layout.html.twig`
- [ ] `templates/pages/my-magazines.html.twig` or equivalent — extend `newsroom-layout.html.twig`
- [ ] `templates/media_manager/...` — if it has a dedicated page template
- [ ] Any other Newsroom-owned pages

### Test Newsroom

- [ ] Visit `/my-content` as authenticated user
- [ ] Verify sidebar shows: Overview, Articles (Drafts/Published), Publications (Magazines/Reading Lists), Media
- [ ] Verify "Back to Discover" link works
- [ ] Verify sidebar closes/opens with toggle button
- [ ] Check content still renders correctly (no layout breakage)
- [ ] Test on mobile
- [ ] Test as admin (does system handle the added nav sections correctly?)

## Translation Keys

Add to each locale file (`translations/messages.{en,de,es,fr,sl}.yaml`):

### Global Navigation Keys
```yaml
nav:
  publications: '[Locale] Publications'
  personal: '[Locale] Personal'
  readingNook: '[Locale] Reading Nook'
  newsroom: '[Locale] Newsroom'
  newMagazine: '[Locale] New Magazine'
  newReadingList: '[Locale] New Reading List'
  newArticle: '[Locale] New Article'
  backToMainNewsroom: '[Locale] Back to Main Newsroom'
  backToDiscover: '[Locale] Back to Discover'
  discoverMoreContent: '[Locale] Discover more content'
  browseArticles: '[Locale] Browse articles'
  publishYourContent: '[Locale] Publish your content'
  createArticle: '[Locale] Create article'
```

### Reading Nook Navigation Keys
```yaml
reading_nook:
  nav:
    overview: '[Locale] Overview'
    all_items: '[Locale] All items'
    saved: '[Locale] Saved'
    bookmarks: '[Locale] Bookmarks'
    interests: '[Locale] Interests'
    collections: '[Locale] Collections'
    reading_lists: '[Locale] Reading lists'
    follow_packs: '[Locale] Follow packs'
```

### Newsroom Navigation Keys
```yaml
newsroom:
  nav:
    overview: '[Locale] Overview'
    my_content: '[Locale] My content'
    articles: '[Locale] Articles'
    drafts: '[Locale] Drafts'
    published: '[Locale] Published'
    publications: '[Locale] Publications'
    magazines: '[Locale] Magazines'
    reading_lists: '[Locale] Reading lists'
    media: '[Locale] Media'
    media_manager: '[Locale] Media manager'
```

Translations files to update:
- [ ] `translations/messages.en.yaml`
- [ ] `translations/messages.de.yaml`
- [ ] `translations/messages.es.yaml`
- [ ] `translations/messages.fr.yaml`
- [ ] `translations/messages.sl.yaml`

## Styling & CSS

- [ ] Review `assets/styles/02-layout/layout.css` to ensure `.sidebar-nav`, `.nav-section`, `.user-nav` styles are consistent
- [ ] Test that the new layouts render with the same visual appearance as before
- [ ] Verify no missing classes or style regressions
- [ ] Check mobile responsive behavior (sidebars, toggles)

## Testing Checklist

### Functional Tests
- [ ] Main global nav shows correct items for anonymous user (Discover, Publications only)
- [ ] Main global nav shows all sections for authenticated user (Discover, Publications, Personal, Create)
- [ ] Reading Nook nav shows all expected items
- [ ] Newsroom nav shows all expected items
- [ ] Back links navigate to correct destinations
- [ ] UserMenu renders at the bottom of sidebars

### UI/UX Tests
- [ ] Sidebar toggle button works
- [ ] No visual regressions
- [ ] No layout shifts or broken layouts
- [ ] Mobile: sidebars are accessible and closable
- [ ] Desktop: sidebars are visible by default

### Accessibility Tests
- [ ] Navigation is keyboard-navigable
- [ ] ARIA labels are present (already in templates)
- [ ] No color-contrast issues introduced

### Performance Tests
- [ ] No new N+1 queries introduced
- [ ] Page load times not degraded
- [ ] Twig component compilation not introducing overhead

## Documentation Updates

- [ ] Confirm `documentation/Newsroom/newsroom-navigation-refactor-plan.md` is linked in `documentation/INDEX.md`
- [ ] Confirm `documentation/Newsroom/navigation-layouts-implementation.md` is linked in `documentation/INDEX.md`
- [ ] Update any developer onboarding docs if they reference the old sidebar structure

## Optional Enhancements (Phase 1.5)

These are optional tasks that can be done alongside Phase 1 or deferred:

- [ ] Enhance `NavigationBuilderTrait` to dynamically exclude routes the user doesn't have access to
- [ ] Add breadcrumb support (show current page name in the sidebar)
- [ ] Highlight active nav items (current page)
- [ ] Add sub-navigation expand/collapse for sections
- [ ] Create a Stimulus controller to manage sidebar state (collapse/expand sections)

## Acceptance Criteria

Phase 1 is complete when:
- [ ] Main global layout is simplified (no individual "my ..." links)
- [ ] Reading Nook pages use `reading-nook-layout.html.twig`
- [ ] Newsroom pages use `newsroom-layout.html.twig`
- [ ] All translation keys are added to all locale files
- [ ] Functional, UI, and accessibility tests pass
- [ ] No visual regressions
- [ ] All existing routes remain unchanged and reachable
- [ ] Documentation is updated
- [ ] CHANGELOG entry added (already done)

## Timeline Estimate

- Layout infrastructure: ~2 hours (already done)
- Controller updates: ~3-4 hours
- Template updates: ~1-2 hours
- Translation updates: ~1 hour
- Testing: ~2-3 hours
- **Total: ~9-12 hours**

## Risks & Mitigation

| Risk | Impact | Mitigation |
|---|---|---|
| Layouts don't render correctly | User sees broken pages | Test each layout early in the process |
| Missing translation keys | UI shows untranslated strings | Add all keys to all locales before testing |
| Sidebar styling breaks | Visual regressions | Use CSS-in-browser DevTools to debug |
| Routes become unreachable | Users cannot navigate | Keep all routes unchanged; only change layout |
| Mobile sidebar behaves unexpectedly | Poor mobile UX | Test sidebar toggle on real mobile devices |

## Post-Phase-1 Notes

After Phase 1 is merged:
- Monitor for user feedback about the simplified sidebar
- Gather analytics on which pages users visit most
- Prepare Phase 2 enhancements (e.g., active nav highlighting, sub-nav)
- Plan when to introduce the Newsroom header/entry point if not already visible

