# Essayist Landing Page

## Overview

Public explainer and join page for Essayist at `/essayist`.

The page explains the pool model, shows the current active member count, walks visitors through the four access steps, and lets logged-in users signal their intent to join.

## Purpose

- Explain how pool membership works (contribute → access, 30 days, renew).
- Show the live member count.
- Let any logged-in user submit a join request (no eligibility checks).
- Gate the CTA by user state: anon / logged-in / pending / member.

## Routes

- **GET `/essayist`** — `App\Controller\StaticController::essayist()`
- **POST `/essayist/request-access`** — `App\Controller\StaticController::requestEssayistAccess()`
- Template: `templates/static/essayist.html.twig`

The route is included in `/api/static-routes`.

## Page Structure

1. **Hero** — headline, lede, CTA (state-dependent).
2. **Pool status** — member count + renewal period.
3. **How it works** — four numbered steps.
4. **Join CTA** — state-dependent: anon → login, logged in → request, pending → waiting message, member → feed link.
5. **Model explainer** — why money flows to members, not the platform.
6. **FAQ** — five common questions.

## Join request flow

1. Logged-in user clicks "Request membership" and POSTs to `/essayist/request-access` with CSRF token.
2. Backend checks:
   - Not already `ROLE_ESSAYIST_MEMBER` → redirect with `already_member`.
   - Not already `ROLE_ESSAYIST_CANDIDATE` → redirect with `already_pending`.
3. Assigns `ROLE_ESSAYIST_CANDIDATE` (pending verification).
4. Admin verifies payment and grants `ROLE_ESSAYIST_MEMBER` via `user:elevate <npub> ROLE_ESSAYIST_MEMBER`.

No eligibility checks (articles, Lightning address). Anyone with a Nostr account can signal intent to join.

## Template variables

| Variable | Type | Description |
|---|---|---|
| `isMember` | `bool` | User has `ROLE_ESSAYIST_MEMBER` |
| `isPending` | `bool` | User has `ROLE_ESSAYIST_CANDIDATE` |
| `memberCount` | `int` | Count of users with `ROLE_ESSAYIST_MEMBER` |
| `joinStatus` | `string\|null` | Status code from redirect after POST |

## Styling

- **Stylesheet:** `assets/styles/04-pages/essayist.css`
- No inline styles, no rounded edges, no shading.

## Translation keys

The page uses the `essayist.landing.*` namespace.

Keys: `hero.*`, `status.*`, `how.*`, `join.*`, `model.*`, `faq.*`.

All five language files must be kept in sync: `en`, `de`, `es`, `fr`, `sl`, `it`.
