# Unfold Publication Administration Specs

Status: draft implementation specification.

> **Kind renumbering (2026-08):** `00-refactor-plan.md` and
> `06-gated-access-and-payments.md` are authoritative for event kinds.
> Audiences are `kind:30879` (not `38110`) and publication payment targets
> are `kind:38133` (not `30133`). Specs 01–05 remain valid for structure,
> flows, and rollout, but read their kind numbers through spec 06.

These specs describe the next Unfold administration layer: owner-signed publication AppData, publication-local feeds, audience tiers, payment targets, owner analytics, and content management. They are intentionally stored inside `src/UnfoldBundle/specs/` because the work is bundle-scoped and should travel with the Unfold implementation.

## Spec Map

| File | Purpose |
| --- | --- |
| `00-refactor-plan.md` | Master refactor plan: phases, decision log, open questions, risks. |
| `01-appdata-and-owner-admin.md` | Owner-signed AppData, `UnfoldSite` ownership fields, and owner-only subdomain administration. |
| `02-audiences-and-payment-targets.md` | Audience tiers and publication payment descriptors — structure and admin behavior (kind numbers superseded by spec 06). |
| `03-feeds-sitemap-footer.md` | Publication RSS feeds, XML sitemap, optional robots endpoint, and two-level footer behavior. |
| `04-owner-dashboard-and-content-management.md` | Owner dashboard pages for analytics, category/article assignment, index editing, and payment setup. |
| `05-tests-and-rollout.md` | Migrations, backward compatibility, cache invalidation, rollout sequence, and test coverage. |
| `06-gated-access-and-payments.md` | Gated content contract: kinds `38133`/`30879`/`8879`/`28877`/`28878`, payment bridge, mint, and relay behavior. |
| `07-reader-interactions.md` | Likes, bookmarks, and highlights on Unfold pages; gated-content interaction rules; subdomain signing. |

## Terms

- DN: the Decent Newsroom host application and platform operator.
- Unfold publication: a Nostr publication rendered by `src/UnfoldBundle` on a registered subdomain.
- `UnfoldSite`: the local row that maps a subdomain to the publication configuration used by the bundle.
- Publication owner: the hex pubkey that controls the root publication index coordinate.
- AppData: owner-signed NIP-78 `kind:30078` event describing the Unfold publication.
- Audience: a subscription tier for a publication, represented by a `kind:38110` Scope Definition.
- Publication payment descriptor: a publication-specific, addressable payment target event that can differ from the owner's personal `kind:10133` payment targets.
- Home relay: the publication's preferred relay URL for fetching and publishing publication context.

## Rollout Order

1. Add parsing DTOs and local schema fields while preserving current coordinate-only `UnfoldSite` rows.
2. Add owner-signed AppData setup to the hosted Unfold flow.
3. Add owner admin shell and access checks on Unfold subdomains.
4. Add RSS, sitemap, robots, and footer context.
5. Add audiences and publication payment descriptors.
6. Add analytics and content-management pages.

## Compatibility

Existing `UnfoldSite` rows only store a publication coordinate. They remain valid:

- The publication coordinate continues to render the site.
- The owner pubkey is derived from `30040:<owner_pubkey>:<dtag>` until an explicit `ownerPubkey` field is backfilled.
- Missing AppData means owner admin shows a setup-required state and prompts the owner to sign AppData.
- Existing AppData with an unmarked `a` tag remains readable as the publication fallback.

New implementations must not require a signed AppData event before public rendering works. AppData is required for owner administration, audiences, payment descriptors, about links, and home relay configuration.