# Unfold Reader Interactions

Reader interactions cover public comments and replies, positive likes, and
generic reposts on hosted publications. Articles (`30023`), chapters (`30041`),
wiki entries (`30818`), and community specifications (`30817`) use the same
interaction identity: their exact Nostr coordinate.

The `default` and `docs` themes share the reader UI and signing service. This
feature does not add statistics, owner analytics, bookmarks, highlights, undo
actions, comment editing/deletion, or gated-content interactions.

## Reader Flow

Existing discussions load from local storage without synchronous relay requests.
Further discussion pages are loaded explicitly. Missing profile information
does not require an inline relay lookup.
Pages contain at most 100 items. Historical revision matching is capped at 40
revisions; discussions and interaction queries exceeding 10,000 candidates
report unavailability instead of silently truncating counts or losing pages.

Readers log in before writing and approve a Nostr signer for the publication's
origin. They do not need to own the publication or have administrator privileges.
The signer account must match the authenticated account.

Comments are plaintext. A reply previews its parent and retains the original
content as the thread root. Cancelling reply mode preserves the draft.
Likes are positive-only; an active `Liked` button does not undo an event.
Reposting asks for confirmation before publicly sharing the original event.
`Reposted` is likewise an active state, not a deletion toggle.

Signed payloads are saved before the HTTP publish request. A transport retry
reuses the same event ID and signature, rather than signing a duplicate event.
Locally accepted interactions remain visible while relay delivery is pending.

## Protocol And Access

| Action | Kind | Identity |
|---|---|---|
| Comment/reply | `1111` | NIP-22 uppercase root tags and lowercase parent tags |
| Positive like | `7` | NIP-25 coordinate, revision, author, and source kind |
| Generic repost | `16` | NIP-18 original event ID, coordinate, author, and source kind |

Kind `6` is reserved for reposting kind-1 notes and is not used for these content
types. Reposts normally embed the actual original signed event JSON; rendered
HTML and reconstructed unsigned metadata are not valid substitutes.
NIP-70-protected originals are referenced without embedding their content.

Every read and write checks the current signed publication tree. A coordinate
must identify a supported public leaf of the current host's publication.
Root/child/content scope tags, removed membership, foreign reply parents,
conflicting references, and signer/session mismatches are rejected.
The identifier's case, significant whitespace, Unicode, colons, and slashes
are retained. Article projections are valid local content sources; an extra
Event row is not assumed to exist.

An unavailable original signature/revision or usable relay hint disables
reposting with an explanation instead of triggering an inline network request.

## Integration

The package owns interaction policy, DTOs, controllers, routes, and themes.
Host adapters provide local Article/Event reads, account identity, profile
metadata, browser signing bootstrap, persistence, relay selection, and delivery.
The bundle has no dependency on the host application's `App` namespace.

The hosted route collection precedes the publication catch-all:

| Method | Path | Purpose |
|---|---|---|
| GET | `/unfold/api/interactions` | Local paginated discussion and public action counts |
| GET | `/unfold/api/interactions/me` | Private reader state and coordinate/account-scoped CSRF token |
| POST | `/unfold/api/interactions/prepare` | Validated unsigned reader event |
| POST | `/unfold/api/interactions/publish` | Verify, accept locally, and queue delivery |
| GET | `/unfold/api/interactions/status` | Private event-specific delivery state |
| POST | `/unfold/api/interactions/retry` | Retry the stored event, not a new signature |

Coordinates are query/body values rather than path segments. Writes enforce
CSRF, origin, reader identity, timestamp and payload limits. The signed-write
request ceiling is 1,000,000 bytes; comments are limited to 4,000 UTF-8 bytes.
The Redis-backed reader write limit is 60 requests per minute per account.

Own state, CSRF tokens, drafts, and delivery state never appear in public
content HTML or shared caches. Interaction API requests are not counted as
additional publication page visits.

## Signing And Login

The host's minimal `unfold-reader-bootstrap` AssetMapper entrypoint registers the
existing signer modal without loading the whole main application layout.
It uses the shared `signer_manager.js` for NIP-07 and NIP-46 signing.
NIP-07 approval and browser storage remain origin-specific.

When the configured session cookie can be shared with publication subdomains,
reader login uses the main domain. With host-only cookies, login stays on the
publication host. `unfold_reader_return` accepts only canonical content paths
on registered publication hosts; the existing owner-admin continuation remains
separate and retains its ownership checks.

## Delivery And Operations

Local acceptance and relay acceptance are different outcomes:

| Status | Meaning |
|---|---|
| `queued` | Accepted locally; asynchronous relay delivery is pending |
| `published` | Selected relays accepted the event |
| `partial` | At least one relay accepted it, but delivery is incomplete |
| `failed` | Relay delivery failed or the current target no longer allows it |

The durable outbox and recovery command retain accepted events across queue
outages. Workers use bounded attempts and actual relay acknowledgement results;
they must recheck current publication membership and public access before sending.
Duplicate HTTP requests, worker retries, and relay ingestion do not create
duplicate local events or inflate distinct-reader counts.
Recovery messages carry their dispatch reservation so the worker can transfer
it to a delivery lease instead of rejecting its own recovered job. Expired
workers cannot overwrite a newer worker's result; duplicate envelopes cannot
bypass relay backoff. Target or interaction tombstones fail closed.

Deploy the additive outbox migration together with the code. The PHP/cron and
Messenger worker containers need the updated command/handler definitions.
The migration is `DoctrineMigrations\Version20261003170000`. Recovery runs once
per minute through `app:dispatch-reader-interactions`; it can also be invoked
explicitly:

```bash
docker compose exec php bin/console app:dispatch-reader-interactions
```

`PublishReaderInteractionMessage` uses `async_low_priority`;
`RefreshReaderInteractionsMessage` uses `async`. Keep consumers for both
transports running. Refresh dispatch is coordinated by a PostgreSQL advisory
lock and a short cache reservation, released on queue-dispatch failure.
Restart the `worker` service for the new handler definitions and the `cron`
service to install the updated crontab when deploying.

Compile the updated AssetMapper assets inside Docker:

```bash
docker compose exec php bin/console asset-map:compile
```

Relay selection uses existing cached/database user and content-author relay
lists plus configured relay infrastructure, not a new global list or a
speculative paid-content home relay.

The dependency-free JavaScript state regressions can be run in a read-only
Docker Node runtime (no npm installation or application bundling):

```bash
docker run --rm --network none -v "${PWD}:/app:ro" -w /app node:22-alpine node --experimental-vm-modules --test tests/JavaScript/unfold-reader-interactions.test.mjs
```

The opt-in PostgreSQL lease check uses an outer transaction and rolls back every
inserted row without enqueueing or broadcasting events:

```bash
docker compose exec -e RUN_UNFOLD_OUTBOX_DB_TESTS=1 php php bin/phpunit tests/Unfold/InteractionOutboxDatabaseTest.php
```

## Known Cosmetic Follow-Up

The docs-theme zap dialog is reported to work, but its styles need completion.
This is cosmetic theme debt, not an invoice or payment failure. The follow-up
should cover amount selection, split payments, QR/invoice display, copy/wallet
controls, loading/errors/success, keyboard focus, and narrow-screen layout.
Keep working payment behavior and use external assets without shadows,
shading, or rounded edges. No zap-style or payment rewrite is included here.
