# Tests And Rollout

## First Slice: Local Setup And Settings

The first slice aligns these specs, shares setup between operator administration
and subscription activation, and persists the current theme setting by immutable
root coordinate. It does not deliver owner administration, reservations, gated
access, audience/payment management, or a replacement portable event.

Schema requirements:

- Add persistence for local publication settings keyed uniquely by the full
  `30040:<pubkey>:<dtag>` root coordinate.
- Existing coordinate-only site mappings remain valid and use the default theme
  when no settings row exists.
- Do not add an `app_data_coordinate` dependency or require an owner-pubkey
  backfill: ownership can be derived from the root coordinate.
- Keep subdomain mapping and billing separate from publication settings.

Targeted setup coverage:

- Valid coordinate and theme settings round-trip through the host adapter.
- Malformed coordinates and unsupported themes are rejected.
- Existing rows without local settings render with the default theme.
- Operator create/edit and subscription activation use the shared setup service.
- Activation retries preserve existing local settings and do not create duplicate
  mappings; conflicting roots/subdomains are rejected rather than reassigned.
- An existing Unfold cannot be retargeted to another root coordinate.
- Settings updates invalidate the affected runtime configuration caches.
- Setup and editing work without signing or publishing kind `30078`.
- Operator writes retain access checks and CSRF protection in every environment.
- Legacy AppData readers, if retained, remain compatibility-only.

## Later Parser And Protocol Coverage

When audience and payment management lands, cover `30879` title, summary,
publication coordinate, repeated prices, duration, and optional payment-target
reference; cover `38133` identity, publication coordinate, repeated NIP-A3
`payto` tags, and duplicate handling consistent with `PaymentTargetService`.

Changing an audience/payment selection persists its local reference without
requiring a second umbrella event publication. Test that refreshing referenced
events does not overwrite independent local selections.

Do not add an AppData linkage feature spec for new setup. Add a portable-definition
round-trip spec only after the future custom event is designed from the working
scoped-access model.

## Later Functional Coverage

Owner administration:

- Both mounts resolve the same full root coordinate and settings.
- Owners have access, non-owners are denied, and anonymous visitors log in.
- A colliding d-tag from another pubkey cannot affect ownership or settings.
- Ordinary settings save without a signer; signed content/configuration events
  with a mismatched owner pubkey are rejected.
- Operator routes remain separate from publication-owner routes.

Discovery:

- `/rss.xml` returns RSS XML; `/feed.xml` redirects to it.
- Category feeds contain only category articles; unknown categories return 404.
- Sitemap entries and feed links use publication-local absolute URLs.
- About URLs appear only when that setting and route are implemented.
- Content types and cache headers match the discovery spec.

Gated access must cover publishing guards and authorization across every read
source: relay, database, graph, and caches. A denied request must not trigger a
broader fallback. Test that authorized content cannot leak through another
reader's request, HTML metadata, feeds, or sitemaps.

## Rollout Sequence

1. Align documentation, introduce persistent local settings, and share setup.
2. Add both owner admin mounts and coordinate-based access checks.
3. Complete footer configuration; RSS/sitemap/robots are already delivered.
4. Consolidate owner content management and publication-scoped editing.
5. Add audience/payment events and local selections; optionally ship Audience
   Preview without checkout, entitlement claims, or fabricated analytics.
6. Enable scoped publishing only with the central home-relay-only guard and
   authorization-aware read/cache paths, covered by unit and protocol tests.
7. Integrate bridge, mint, and relay against the agreed contract.
8. Derive and test a custom portable definition by reconstructing the same
   publication on a clean host.

Run targeted PHPUnit and template checks inside Docker for each implemented
slice. Documentation-only changes require consistency and link checks, not new
runtime tests. Preserve working entry points until replacement workflows are
verified.
