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
- **POST `/essayist/request-access`** — `App\Controller\StaticController::requestEssayistAccess()` *(opens 1.6.2026; CTA disabled until then)*
- **POST `/essayist/early-bird`** — `App\Controller\StaticController::claimEarlyBird()` *(available until 31.5.2026)*
- Template: `templates/static/essayist.html.twig`

The route is included in `/api/static-routes`.

## Page Structure

1. **Hero** — headline, lede, CTA (state-dependent).
2. **Pool status** — member count + renewal period.
3. **Early bird** — promo section; logged-in users claim free June access with one click. Disabled/confirmed once claimed.
4. **How it works** — four numbered steps.
5. **Join CTA** — disabled until 1.6.2026; will accept requests gated on the relay initialization fee only.
6. **Model explainer** — why money flows between members, not through the platform.
7. **FAQ** — five common questions.

## Join request flow (opens 1.6.2026)

1. Logged-in user clicks "Request access" and POSTs to `/essayist/request-access` with CSRF token.
2. Backend checks:
   - Not already `ROLE_ESSAYIST_MEMBER` → redirect with `already_member`.
   - Not already `ROLE_ESSAYIST_CANDIDATE` → redirect with `already_pending`.
3. Assigns `ROLE_ESSAYIST_CANDIDATE` (pending verification).
4. Admin verifies relay initialization fee payment and grants `ROLE_ESSAYIST_MEMBER`.

**No eligibility requirements** (no minimum article count, no Lightning address check). The only gate is the one-time 100-sat relay initialization fee, collected outside this flow.

## Early bird flow (active until 31.5.2026)

1. Logged-in user clicks "Claim your free June" and POSTs to `/essayist/early-bird` with CSRF token.
2. Backend checks: not already `ROLE_ESSAYIST_EARLY_BIRD` → redirect with `already_early_bird`.
3. Assigns both `ROLE_ESSAYIST_EARLY_BIRD` and `ROLE_ESSAYIST_MEMBER`. No charge, no approval step.

## Template variables

| Variable | Type | Description |
|---|---|---|
| `isMember` | `bool` | User has `ROLE_ESSAYIST_MEMBER` |
| `isPending` | `bool` | User has `ROLE_ESSAYIST_CANDIDATE` |
| `isEarlyBird` | `bool` | User has `ROLE_ESSAYIST_EARLY_BIRD` |
| `memberCount` | `int` | Count of users with `ROLE_ESSAYIST_MEMBER` |
| `joinStatus` | `string\|null` | Status code from redirect after POST |

## Styling

- **Stylesheet:** `assets/styles/04-pages/essayist.css`
- No inline styles, no rounded edges, no shading.

## Translation keys

The page uses the `essayist.landing.*` namespace.

Keys: `hero.*`, `status.*`, `how.*`, `join.*`, `model.*`, `faq.*`.

All five language files must be kept in sync: `en`, `de`, `es`, `fr`, `sl`, `it`.
