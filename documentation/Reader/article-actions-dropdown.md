# Article Actions Dropdown

The article social strip places secondary Nostr actions in an overflow menu.
The current menu offers:

- Copy the article's naddr.
- Copy its `kind:pubkey:slug` coordinate.
- Broadcast the existing signed article event to relays for signed-in readers.
- Broadcast to the configured Essayist relay for eligible members or admins.

Protected events expose broadcast actions only to their author. The backend
also enforces publishing permissions; hiding a button is not the authorization
boundary.

Comments, likes, bookmarks, sharing, and tipping belong to the surrounding
`ArticleSocialActions` component. Its bookmark button uses `ui--card-bookmark`,
the same controller as article cards; see [bookmarks](bookmarks.md).

## Component

- `src/Twig/Components/Molecules/ArticleActionsDropdown.php`
- `templates/components/Molecules/ArticleActionsDropdown.html.twig`

| Prop | Type | Purpose |
|---|---|---|
| `article` | `Article` | Article entity and event identifiers |
| `coordinate` | `string` | Nostr coordinate |
| `canonicalUrl` | `string` | URL passed by the surrounding social component |
| `naddrEncoded` | `string` | Encoded Nostr address to copy |
| `isProtected` | `bool` | NIP-70 protected-event flag |
| `relays` | `?array` | User's write relays |

The normal integration is inside
`templates/components/Molecules/ArticleSocialActions.html.twig`, which passes
these values to the dropdown.

## Browser behavior

`assets/controllers/ui/article_actions_dropdown_controller.js`
(`ui--article-actions-dropdown`) uses `trigger` and `menu` targets. It toggles
the menu and its `aria-expanded` state, closes on outside clicks, copies
`data-copy` values through the Clipboard API, and posts broadcasts to
`/api/broadcast-article`. The Essayist action supplies the configured relay
explicitly. Status messages use `window.showToast()`.

Styles live in `assets/styles/03-components/article-actions-dropdown.css`;
the social strip has separate `article-social-actions.css` styling.

See [article broadcasting](../Newsroom/article-broadcast-feature.md) and
[protected articles](../Newsroom/nip70-protected-articles.md) for backend behavior.
