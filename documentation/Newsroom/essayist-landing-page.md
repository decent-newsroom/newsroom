# Essayist Landing Page

## Overview

Adds a public static explainer page for Essayist at `/essayist`.

The page is intentionally static for now. It explains the writer-first launch model, shows the current phase, lists writer requirements, and includes a timeline for how Essayist opens.

## Purpose

The page gives Essayist a public home before the interactive writer-request flow and supporter flow are fully implemented.

It is designed to:

- explain the public writer-signup-first rollout;
- show that reader/supporter access is coming later;
- communicate the moderation standard;
- set expectations with a visible launch timeline.

## Route

- **Path:** `/essayist`
- **Controller:** `App\Controller\StaticController::essayist()`
- **Template:** `templates/static/essayist.html.twig`

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

This page does **not** yet implement:

- the writer self-request POST action;
- candidate role assignment;
- supporter signup/payment flow.

It is an explainer page first, with copy aligned to the current Essayist launch model.

