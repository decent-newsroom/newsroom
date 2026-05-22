# NIP-70 Protected Articles

## Overview

[NIP-70](../NIP/70.md) defines the concept of "Protected Events": Nostr events that carry the `["-"]` tag to signal that the author does not want the event re-broadcast by third parties. Cooperating relays reject any attempt to publish such an event from a client that is not authenticated as the original author.

This platform respects NIP-70 on both the **display layer** and the **broadcast API**.

---

## How It Works

### Detection

`Article::isNip70Protected()` inspects the stored raw event's `tags` array and returns `true` when any tag with key `"-"` is found. This is the canonical source of truth, used by both the template layer and the API controller.

### Article Page Badge

When an article is NIP-70 protected, a 🔒 **Protected (NIP-70)** badge is displayed below the article byline. The badge has a tooltip explaining that the author requested third parties not re-broadcast it.

### Broadcast Actions (UI)

The article actions dropdown (`ArticleActionsDropdown` Twig component) contains **Broadcast to Relays** and **Broadcast to Essayist** actions.

| Viewer | Protected article | Unprotected article |
|--------|-------------------|---------------------|
| Author | ✅ Button shown with 🔒 indicator | ✅ Button shown normally |
| Anyone else | ❌ Button hidden | ✅ Button shown |

`ArticleActionsDropdown::isAuthorOfArticle()` performs the author check server-side during template rendering, converting the current user's npub to hex and comparing with `hash_equals()` against the article's stored pubkey.

### Broadcast API (Server-Side Enforcement)

`POST /api/broadcast-article` enforces NIP-70 regardless of UI state.

If the target article is NIP-70 protected:

| Requester | HTTP Status | Response |
|-----------|-------------|----------|
| Unauthenticated | `401` | `"This article is NIP-70 protected. Authentication required to broadcast it."` |
| Authenticated, not author | `403` | `"This article is NIP-70 protected. Only the author can broadcast it."` |
| Authenticated author | `200` | Normal broadcast response |

This prevents the restriction from being bypassed via direct API calls.

---

## Authoring (Editor)

When the article editor publishes with the **Publish ONLY to Essayist** toggle active, the `["-"]` NIP-70 tag is automatically added to the event. This means:

- Cooperating relays will reject any re-broadcast attempt from non-authors.
- The platform UI hides the broadcast button for all viewers except the author.
- The API rejects non-author broadcast requests with HTTP 403.

---

## Relay Behaviour

Relays that implement NIP-70 act as a second line of defence. Even if a client attempts to re-publish a protected event, the relay will challenge the client with NIP-42 AUTH and verify that the authenticated pubkey matches the event's `pubkey` field before accepting the event.

---

## Files

| File | Role |
|------|------|
| `src/Entity/Article.php` | `isNip70Protected()` — canonical tag scan |
| `src/Twig/Components/Molecules/ArticleActionsDropdown.php` | `isAuthorOfArticle()` — author check for template |
| `templates/components/Molecules/ArticleActionsDropdown.html.twig` | Conditional broadcast button visibility |
| `templates/pages/article.html.twig` | Protected badge in byline |
| `src/Controller/Api/ArticleBroadcastController.php` | Server-side NIP-70 enforcement |
| `assets/styles/03-components/article.css` | `.article-protected-badge` styles |
| `assets/styles/03-components/article-actions-dropdown.css` | `.badge-protected` styles |

