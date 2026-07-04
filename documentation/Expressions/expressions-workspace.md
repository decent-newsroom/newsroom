# Expressions Workspace

## What it is

The Expressions Workspace is a new personal section for authenticated users, parallel to Reading Nook and Newsroom.

Route: `/expressions/workspace`

Purpose:
- Create and edit feed expressions (kind `30880`)
- Switch quickly between expression and spell result lists
- Open result views to test feeds with the current user context

## Navigation and layout

The section uses the shared app shell (`app-shell`) with a local left sidebar, matching the same flat visual style used by Reading Nook and Newsroom.

Implemented with:
- `NavigationBuilderTrait::buildExpressionsNav()`
- `templates/expressions-layout.html.twig`
- `nav.personal` link to `Expressions`

## Pages and flow

- **Workspace** (`/expressions/workspace`)
  - Header + section tabs (Expressions / Spells)
  - Quick action to create a new expression
  - Embedded expression and spell lists for quick feed testing
- **Create/Edit** (`/expressions/create`, `/expressions/edit/{npub}/{dtag}`)
  - Same section shell/navigation as workspace
  - NIP-EX builder for drafting and publishing expression events
- **Public directory** (`/expressions`)
  - Kept as a public listing page
  - Logged-in users can jump to the personal workspace

## Styling notes

Workspace and builder pages reuse the existing Newsroom/Reading Nook visual language:
- flat borders
- no rounded edges
- no shadow/elevation treatments
- shared page/header/tab patterns (`my-content-*` classes)

