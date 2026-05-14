# Essayist Landing Page

## Overview

Adds a public explainer page for Essayist at `/essayist` with a working writer signup request action.

The page explains the writer-first launch model, shows the current phase, lists writer requirements, includes a timeline, and lets logged-in users request writer access when eligibility checks pass.

## Purpose

The page gives Essayist a public home while also implementing the first interactive piece: writer self-request.

It is designed to:

- explain the public writer-signup-first rollout;
- show that reader/supporter access is coming later;
- communicate the moderation standard;
- set expectations with a visible launch timeline.
- enable candidate self-enrollment through role assignment.

## Routes

- **Path:** `/essayist`
- **Controller:** `App\Controller\StaticController::essayist()`
- **Template:** `templates/static/essayist.html.twig`
- **POST:** `/essayist/request-writer-access`
- **Controller:** `App\Controller\StaticController::requestEssayistWriterAccess()`

The route is also included in `/api/static-routes`.

## Page Structure

The landing page contains:

1. **Hero** — short explanation of Essayist and primary calls to action.
2. **Current status** — writer phase live, reader/supporter phase coming soon.
3. **Model explainer** — why Essayist opens with writers first.
4. **Writer requirements** — 3 deduplicated essays known to DN, Lightning address, native Nostr longform.
5. **Moderation section** — moderators review and can downgrade approved writers.
6. **Timeline** — three launch stages:
   - public writer signup;
   - founding pack growth;
   - reader support opening.
7. **Next steps CTA** — sign in / write / read changelog.

## Writer signup flow

When a logged-in user submits writer signup:

1. POST to `/essayist/request-writer-access` with CSRF token.
2. Backend checks current roles:
   - already author → no-op
   - already candidate → no-op
3. Eligibility checks run:
   - at least 3 deduplicated kind `30023` articles by author pubkey
   - `lud16` present on the user profile
4. If eligible, assign `ROLE_ESSAYIST_CANDIDATE` to the user.
5. Redirect back to `/essayist` with a status code shown in-page.

Role assignment remains moderation-first:

- self-request assigns **candidate** role only;
- moderators still promote to `ROLE_ESSAYIST_AUTHOR`.

## Styling

- **Stylesheet:** `assets/styles/04-pages/essayist.css`
- Imported in `assets/app.js`

The styling follows project constraints:

- no inline styles;
- no rounded edges;
- no shading / box shadows;
- simple bordered blocks and timeline rows.

## Translation keys

The page uses the `essayist.landing.*` translation namespace.

Keys were added to:

- `translations/messages.en.yaml`
- `translations/messages.de.yaml`
- `translations/messages.es.yaml`
- `translations/messages.fr.yaml`
- `translations/messages.sl.yaml`
- `translations/messages.it.yaml`

## Notes

Supporter signup/payment flow is still pending. The landing page currently covers writer self-request only.

It is an explainer page first, with copy aligned to the current Essayist launch model.

