# Blog Journey — Creator Onboarding Wizard

## Overview

The Blog Journey provides a marketing landing page (`/blog/start`) that funnels creators into the magazine wizard. Publications on Decent Newsroom are called "magazines" because they natively support guest articles and remixing/syndication out of the box.

The magazine wizard is a 6-step flow: **Magazine → Categories → Articles → Review → Subdomain → Done**. Steps 5 (Subdomain) and 6 (Done) are part of the default wizard for all users — after publishing, every user is offered paid subdomain hosting (120,000 sats/year via Lightning). The subdomain step can be skipped.

## Routes

| Route | Name | Description |
|-------|------|-------------|
| `/start-blog` | `start_blog` | Redirect to blog journey landing |
| `/blog/start` | `blog_journey_landing` | Marketing landing page |
| `/blog/setup` | `blog_journey_setup` | Login, dispatch sync, redirect to magazine wizard |
| `/magazine/wizard/setup` | `mag_wizard_setup` | Step 1: Magazine metadata |
| `/magazine/wizard/categories` | `mag_wizard_categories` | Step 2: Categories |
| `/magazine/wizard/articles` | `mag_wizard_articles` | Step 3: Articles |
| `/magazine/wizard/review` | `mag_wizard_review` | Step 4: Review & Sign |
| `/magazine/wizard/subdomain` | `mag_wizard_subdomain` | Step 5: Choose a subdomain (skippable) |
| `/magazine/wizard/launched` | `mag_wizard_launched` | Step 6: Done — confirmation & next steps |
| `/magazine/wizard/api/subdomain-check` | `mag_wizard_subdomain_check` | API: Check subdomain availability |

## Architecture

### Controllers

**`BlogJourneyController`** — landing page + entry point only:
- `landing()` — marketing page
- `setup()` — login prompt (if unauthenticated), dispatches `FetchAuthorArticlesMessage`, redirects to `mag_wizard_setup`

**`MagazineWizardController`** — the full 6-step wizard:
- Steps 1–4: Magazine setup, categories, articles, review & sign (unchanged)
- `subdomain()` — step 5: subdomain picker with pricing, posts to `publication_subdomain_create` for Lightning payment
- `launched()` — step 6: confirmation, tips, next steps
- `subdomainCheck()` — API for inline subdomain availability

### Templates

**Blog journey** (`templates/blog-journey/`):
- `landing.html.twig` — marketing page
- `setup-login.html.twig` — login prompt (uses `magazine/_wizard_steps.html.twig`)

**Magazine wizard** (`templates/magazine/`):
- `_wizard_steps.html.twig` — 6-step progress indicator
- `magazine_setup.html.twig` — step 1
- `magazine_categories.html.twig` — step 2
- `magazine_articles.html.twig` — step 3
- `magazine_review.html.twig` — step 4 (redirects to subdomain after publish)
- `magazine_subdomain.html.twig` — step 5
- `magazine_launched.html.twig` — step 6

### Post-publish redirect

After a successful Sign & Publish on the review step, the `nostr_index_sign_controller.js` Stimulus controller redirects to `/magazine/wizard/subdomain` after a brief success message. This is driven by the `redirectUrl` Stimulus value passed from the review template.

### Translations

- `wizard.*` — step labels and subdomain/launched page content (in `translations/messages.en.yaml`)
- `journey.*` — landing page and login prompt content

## Flow Diagram

```
/start-blog (redirect)
    ↓
/blog/start (landing page)
    ↓ "Get Started"
/blog/setup
    ├── Not logged in → show login prompt
    └── Logged in → dispatch sync, redirect ↓
/magazine/wizard/setup (step 1)
    ↓
/magazine/wizard/categories (step 2)
    ↓
/magazine/wizard/articles (step 3)
    ↓
/magazine/wizard/review (step 4) → sign & publish
    ↓ (auto-redirect after publish)
/magazine/wizard/subdomain (step 5)
    ├── Already has subdomain → show it, continue
    ├── New → subdomain picker → Continue to Payment
    │       ↓ (posts to publication_subdomain_create)
    │   /subscription/publication-subdomain/invoice (Lightning QR)
    ├── Skip → continue to launched
    ↓
/magazine/wizard/launched (step 6)
    confirmation, tips, subdomain status, next steps
```
