# Automatic Role Promotion

## Overview

Users are automatically promoted to higher roles based on their publishing activity on Decent Newsroom:

| Action | Role Granted |
|--------|-------------|
| Publish an article (kind 30023) | `ROLE_WRITER` |
| Publish a reading list or magazine (kind 30040) | `ROLE_EDITOR` |

Only users who already have an account (have logged in at least once) are promoted. Unknown npubs are silently skipped.

## Architecture

### UserRolePromoter Service

**File:** `src/Service/UserRolePromoter.php`

Centralizes the role-assignment logic. Converts a hex pubkey to npub, looks up the `User` entity, and adds the role if missing.

Methods:
- `promoteToWriter(string $pubkeyHex)` — grants `ROLE_WRITER`
- `promoteToEditor(string $pubkeyHex)` — grants `ROLE_EDITOR`

### Integration Points

| Entry Point | Role | Trigger |
|-------------|------|---------|
| `EditorController::publishNostrEvent()` | WRITER | Article published via the editor (non-draft) |
| `ArticleEventProjector::projectArticleFromEvent()` | WRITER | Article ingested from relay subscription |
| `MagazineWizardController::publishIndexEvent()` | EDITOR | Reading list or magazine published via the wizard |
| `GenericEventProjector::projectEventFromNostrEvent()` | EDITOR | Kind 30040 event ingested from relay subscription |

### User Entity

**File:** `src/Entity/User.php`

Helper methods:
- `isWriter()` — checks if user has `ROLE_WRITER`
- `isEditor()` — checks if user has `ROLE_EDITOR`

## Behaviour

- Promotion is idempotent: calling it multiple times for the same user and role has no effect.
- Errors during promotion are caught and logged as warnings; they never break the publishing flow.
- Draft articles (kind 30024) do **not** trigger ROLE_WRITER promotion.

