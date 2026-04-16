# Expressions List & View Pages

## Overview

Public-facing pages for browsing all published kind:30880 feed expression events and viewing their evaluated results. Follows the same architectural pattern as the follow-packs listing and view pages.

## Routes

| Route | Name | Auth | Description |
|-------|------|------|-------------|
| `GET /expressions` | `expression_list` | Public | Lists all feed expressions |
| `GET /expression/{npub}/{dtag}` | `expression_view` | Public (results need login) | Shows evaluated results |
| `GET /expressions/create` | `expression_create` | `ROLE_USER` | Expression builder |
| `GET /expressions/edit/{npub}/{dtag}` | `expression_edit` | `ROLE_USER` | Edit own expression |

## Event Structure (kind 30880)

Expressions follow standard Nostr conventions for replaceable parameterised events:

- **`content`** — free-text description of what the expression does (event property, not a tag)
- **`d`** tag — unique identifier (slug), used for replaceable event addressing
- **`title`** tag — human-readable title for display
- **`summary`** tag — short summary (mirrors content for tag-based clients)
- **`alt`** tag — fallback text for clients that don't understand kind 30880
- **`op`**, **`match`**, **`not`**, **`cmp`**, **`text`**, **`input`** tags — pipeline stages

## Architecture

### Component: `ExpressionList`

- **PHP class:** `src/Twig/Components/Organisms/ExpressionList.php`
- **Template:** `templates/components/Organisms/ExpressionList.html.twig`
- **Type:** Twig Component (Organism)

The component:

1. Fetches all kind 30880 events from the database
2. Deduplicates by `pubkey:d-tag` (keeps the latest version)
3. Extracts metadata from tags: `title`, `summary`, `d` (slug)
4. Counts pipeline stages (op, match, not, cmp, text, input tags)
5. Falls back to content for summary and d-tag for title
6. Resolves author profile metadata from Redis cache
7. Returns structured array for template rendering

### Controller: `ExpressionController`

- **File:** `src/Controller/ExpressionController.php`
- **List route:** Renders `expressions/index.html.twig` using `<twig:Organisms:ExpressionList />`
- **View route:** Fetches expression event by naddr coordinates, evaluates it via `ExpressionService::evaluateCached()`, paginates results, resolves author metadata, renders as article cards via `<twig:Organisms:CardList />`

### Expression View Behaviour

- **Authenticated users:** Expression is evaluated with the user's personal context (`$me`, `$contacts`, `$interests`). Results are paginated (20 per page) and displayed as standard article cards.
- **Anonymous users:** A login prompt is shown instead of results, since expression evaluation requires authentication.
- **Evaluation errors:** Caught gracefully with an error message displayed to the user.

### Templates

| Template | Description |
|----------|-------------|
| `templates/expressions/index.html.twig` | List page with heading and `ExpressionList` component |
| `templates/expressions/view.html.twig` | View page with expression metadata, article cards, pagination |
| `templates/components/Organisms/ExpressionList.html.twig` | Card grid of expressions with title, description, stage count, author |

### Navigation

Link added to the sidebar navigation under the "Newsroom" section, visible to all users.

### Translations

Keys added across all 5 locales (en, de, es, fr, sl):

- `nav.expressions` — sidebar link label
- `expressionsList.*` — list page strings (heading, eyebrow, noExpressions, stages, createdBy, viewResults, edit)
- `expressionView.*` — view page strings (by, noResults, loginRequired, backToList)
- `expressions.*` — builder form strings (title, titlePlaceholder, content, contentPlaceholder, etc.)

