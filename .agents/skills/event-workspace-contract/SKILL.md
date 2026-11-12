---
name: event-workspace-contract
description: >-
  Preserve the shared event workspace contract: event identity, a/e/naddr reference resolution, ordered typed relationships, tag-based previews that never need rendered HTML, and access rules. Use when changing NostrEmbed, previews, graph/parsed_reference resolution, collection expansion, Reading Nook, or My Content browsing.
---

# Skill: Work on the Event Workspace Contract

Use this guide when changing event identity, reference resolution, collection
expansion, event previews, or the shared browser used by Reading Nook and My
Content.

The design proposal is
[Event Workspace and Composable Previews](../../../documentation/Reader/event-workspace-iteration-plan.md).
Read its relevant section before changing behavior. Treat the signed Nostr event
and raw tags as the source of truth; rendered HTML, Article projections, graph
indexes, and profile metadata are derived or optional data.

## Invariants

- Every readable event gets a useful generic representation, even if its kind
  has no specialist viewer, Article row, cached body HTML, or kind-specific
  template.
- A body parse/render failure must not discard event identity, tag-derived
  metadata, relationships, or status.
- Preserve raw tag order, positions, duplicate occurrences, and exact target
  identity. Derived tag facets or deduplicated values are not a substitute for
  the ordered child references.
- Keep exact event IDs distinct from replaceable coordinates and latest-version
  lookup. Respect version pins and relay hints according to each relevant NIP or
  NKBIP; do not treat tag positions as universal across kinds.
- Interpret relationships in source-kind context. A `p` tag in a kind `3`
  contact list is a followed profile; in a kind `39089` pack it is a member,
  not necessarily a follow. In another kind it may be a mention, author, or
  recipient.
- Treat warning evidence as authoritative even when supplied flags disagree.
  Keep machine-valued tags, such as a kind `9735` zap request in `description`,
  out of human-readable previews while retaining the signed raw tags.
- Missing targets remain visible as reference/status entries. Graph indexes
  accelerate traversal; raw event tags remain a fallback.
- One target can occur in multiple collections. Detect cycles along the current
  traversal path, not through a global visited set that hides legitimate
  repeated occurrences.
- Rendering is read-only. Do not fetch synchronously, persist events, republish,
  reorder tags, or mutate signed data from a Twig leaf component.
- Enforce author suppression, deletion/tombstone, scoped-content access, user
  mute, and privacy rules for rows, counts, fragments, caches, and updates.

## Procedure

1. Identify the source scope and reference shape: event ID, addressable
   coordinate, replaceable identity, profile key, local subscription, Article
   projection, or Bookshelf result. Do not fabricate a Nostr event for a local
   virtual folder.
2. Reuse existing identity, revision, repository, profile-cache, Bookshelf, and
   async-fetch services. Add a narrow adapter only where source data currently
   has no shared representation.
3. Keep resolution separate from presentation. Return explicit available,
   missing, loading, restricted, invalid, and failed states as appropriate.
   Never collapse a converter failure into "event not found."
4. Enumerate relationships with source-tag position and kind-aware role before
   resolving targets. Preserve unresolved and duplicate occurrences and order.
   Paginate large collections; do not discover every child merely because its
   parent was rendered.
5. Apply viewer/owner access checks server-side for every fragment/action.
   Browser props and URL parameters do not grant permission.
6. Add tests for malformed and partial tags, exact-vs-latest references,
   unresolved children, duplicate/shared children, cycles, owner scope, and body
   rendering failure, choosing the narrowest relevant suites.
7. Update the iteration plan and feature documentation if the architectural
   contract changes. For user-facing behavior, update translations and the
   development changelog as required by project conventions.

## Existing foundations

- `src/Service/EventWorkspace/EventPresentationFactory.php` and
  `src/Dto/EventWorkspace/` provide pure snapshots of already-authorized
  event fields; they do not enforce access or resolve references.
- `src/Twig/Components/Molecules/EventPreview.php` is a static, presentation-only
  building block, not yet a replacement for existing surfaces.
- `src/Service/Graph/RecordIdentityService.php`
- `src/Service/Graph/CurrentVersionResolver.php`
- `src/Service/Graph/ReferenceParserService.php`
- `src/Service/Graph/GraphLookupService.php`
- `src/Service/ReadingListContentPreviewProvider.php`
- `src/Service/Cache/RedisCacheService.php`
- `src/MessageHandler/FetchEventFromRelaysHandler.php`
- `src/Twig/Components/Molecules/NostrEmbed.php`
- `src/Controller/Reader/ReadingNookController.php`
- `src/Controller/Newsroom/MyContentController.php`

Do not assume these APIs cover all reference types or preserve all unresolved
occurrences. Read the implementation and tests before relying on them.

## Completion checklist

- [ ] Generic event presentation does not require kind-specific support or body HTML.
- [ ] Raw ordered relationships and unresolved targets are retained.
- [ ] Exact references and current-coordinate references remain distinct.
- [ ] Access policy applies to all returned data, including counts and fragments.
- [ ] Batching, pagination, cycle, and depth behavior are covered where relevant.
- [ ] Existing Reading Nook, My Content, publication, and embed behavior is preserved.
- [ ] Related docs, translations, and changelog entries are updated where applicable.
