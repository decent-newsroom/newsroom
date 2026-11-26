# Article recovery copies

The new-article editor keeps unsigned recovery copies independently of the Nostr
signing and publishing flow. Writers can restore a copy, delete individual
browser or server copies, or export and import a JSON recovery file.

## How it works

Changes are saved in browser local storage at short intervals while editing.
Blank articles with neither a title nor content are not saved, and clearing
an article does not overwrite its last recovery copy. Manually edited event
JSON is preserved even if the form has no title or content.
Signed-in writers also save to their account on the server at a longer interval; the
status reports storage failures. Open **Relays** in the right-hand settings
sidebar (on mobile, open **Show Settings** first) to find **Recovery copies**
below the relay settings. It lists browser and server copies separately.
Restoring first saves any current article or metadata changes
in the browser and then opens the selected copy as a new working copy. A failed
browser save prevents replacement of the current work.

Snapshots include the editor state, article form fields and manually edited
event JSON, but not the login method, public-key field, CSRF token or files.
Dynamic advanced metadata fields are restored with the article. Saving a
recovery copy does not sign, publish, or create a Nostr draft. Copies are not
automatically deleted after publication; delete each unwanted copy explicitly.

## Privacy and limitations

Browser copies are scoped by the signed-in Nostr public key (or anonymous
session) but are accessible to anyone with access to that browser profile.
Server copies are restricted to the signed-in account and are stored as
plaintext JSON in PostgreSQL, not end-to-end encrypted. Exported files are
also plaintext. Browser data can be cleared, and a server save may not finish
before navigation or loss of connectivity. Check the status or export a file.
Anonymous copies remain in the anonymous browser namespace; the existing
login continuation handles work in the current editor separately.

This feature applies to the new-article editor, not editing an existing
published article or Nostr draft. Server copies have a 2 MiB limit per snapshot;
browser storage may have different quota limits. Recovery does not save
uploaded media files themselves. Recovery copies are retained until deleted
by their owner; the operator's ordinary database backups may retain deleted
copies longer.

## Implementation

`editor--recovery` captures form and editor state and queues account-scoped
requests to `ArticleRecoveryController`. The API requires authentication and
CSRF protection for changes, and its responses are private and non-cacheable.
`ArticleRecovery` stores snapshots by UUID and owner in the `article_recovery`
table. No relay or signer is involved. Apply the Doctrine migration before
enabling the server-backed portion.
