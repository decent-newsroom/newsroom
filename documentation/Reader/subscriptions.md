# Local subscriptions

Subscriptions are free, account-local notifications about profiles, magazines,
lists, and follow packs. They do not publish a Nostr follow or change a signed
list. There is no free/Pro split and no five-source limit.

## Reader experience

Use **Subscribe** on a profile, magazine, follow pack, or eligible shared
collection preview. The same control shows **Subscribed** and can unsubscribe.
The source manager also accepts an npub, hex public key, naddr, or
`kind:pubkey:identifier` coordinate. Identifiers remain case-sensitive.

**Subscriptions** opens `/subscriptions`; **Manage subscriptions** opens
`/subscriptions/sources`. Reading Nook links to both. The existing home
subscription feed uses the same visibility rules. Legacy `/updates` and
`/updates/subscriptions` URLs remain available.

The feed preserves separate articles from the same author instead of collapsing
them into one row. Items distinguish new articles, revised articles, magazine or
list changes, and follow-pack changes. When available, they show added and removed
references, reordered members, changed source details, and body changes. A
collection delivery includes a link to the subscribed source, including when a
nested magazine section caused the delivery.

Opening the feed does not mark its items read. Each unread item has an explicit
**Mark as read** action. Pages contain up to 50 candidate deliveries, with an
older-items cursor; access filtering can make a page shorter or empty.

## Supported sources and matching

- Profiles match longform articles (`30023`) and publication indexes (`30040`),
  not private account configuration changes.
- Publication indexes (`30040`) match their own revisions and referenced content.
  Nested index traversal is limited to depth 6 and 128 index visits per source,
  with path-local cycle detection. Exact event IDs and pinned address versions
  remain distinct from unpinned latest-coordinate references.
- Follow packs (`39089`) match their own revisions and articles by their `p`
  members. Pack topic labels do not turn into topic subscriptions.
- Existing list sources remain supported: `3`, `10000`, `10015`, `30003`,
  `30004`, `30005`, `30006`, and `30015`. Membership is interpreted by source
  kind; for example, mute-list people do not become followed authors.

An event produces at most one delivery per reader even when several sources
match it. Subscription time must precede the event timestamp. Unsubscribing
deactivates the local source; resubscribing reuses it and starts eligibility
from the new subscription time.

Subscriptions react to **events observed by this instance's existing ingestion
paths**. This feature does not add remote polling, unlimited relay discovery,
or a guarantee of complete external history. Async matching uses the source
membership currently available locally, not a reconstruction of membership at
every historical publication time.

## Shared local change history

`SubscriptionChangeRecorder` stores one immutable `subscription_change` record
per relevant event revision, shared by every recipient. It retains identity,
timestamps, signed-source tags, a body hash, the predecessor event ID, and a
semantic delta. It does not retain article bodies or rendered HTML.

Generic and gateway Event cleanup record changes before deleting old raw
revisions. The dedicated Article importer records Article-only ingestion as
well, including the predecessor available before revision replacement. Replays
reuse existing records; PostgreSQL coordinate locks serialize recording.
NIP-01 ordering rejects stale revisions and uses the lower event ID for equal
timestamps.

First observation of a collection establishes a baseline rather than inventing
an entire set of additions. An existing locally available predecessor can seed
the baseline during deployment. New articles may notify on first observation.
Semantic no-op revisions do not produce new activity. Diffs preserve duplicate
reference occurrences and version pins; they are not set-only comparisons.

`notification` continues to hold each recipient's delivery, source, and read
state, with an optional link to the shared change. Existing deliveries do not
need invented history. PostgreSQL delivery locks and the existing user/event
unique constraint prevent duplicate delivery.

## Visibility and live refresh

Feed items, unread counts, source labels, collection ancestry, and changed
targets are authorized when read. Current suppression, tombstones, hidden
coordinates, reader mutes, scope restrictions, sensitive tags, and Article
entitlements still apply. Changed targets resolve locally, at most 50 per item.
Unreadable or missing targets expose neither titles nor exact hidden counts.
Article projections can resolve locally even when no generic Event row exists.

Historical article items require an exact readable Article projection; purged
old revisions can therefore disappear from the feed. Collection ancestry is
also checked against currently readable local indexes. A subscription never
grants access to restricted content.

Mercure sends only a generic private activity signal, not titles, authors,
counts, or content URLs. The browser refreshes authenticated HTML from
`/subscriptions/items`; older pages are not replaced by the newest page.
Responses are private/no-store and Turbo snapshot caching is disabled.

NIP-09 deletion removes matching change history and delivered metadata in
addition to the existing deletion behavior. There is currently no automatic
age-based retention limit for other change snapshots; operators should account
for their storage growth.

## Deployment and retired payments

Apply migration `Version20261116233158` before restarting application and
Messenger workers. It creates `subscription_change` and adds the nullable
`notification.change_id` relation without replacing existing subscriptions or
payment history.

Updates Pro is retired. Its old landing, purchase, and renewal links redirect
to the free subscriptions page; no new invoices can be created by the legacy
service. Payment entities, status values, receipt settlement, and expiry cleanup
remain for historical records and outstanding receipts. They no longer gate
local subscriptions or grant a paid subscription role on settlement. Retirement
does not delete payment records, issue refunds, or introduce a replacement paid
offer.
