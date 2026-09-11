# Unified Publication Admin And Mounts

Status: draft implementation specification.

Supersedes the "owner admin lives on the subdomain" framing in
`01-appdata-and-owner-admin.md` and `04-owner-dashboard-and-content-management.md`.
Those specs remain authoritative for *what* the admin pages do; this spec is
authoritative for *where they mount, how the publication is identified, and who
may access them*.

## Goal

One publication administration implementation, reachable from two mounts, with
the publication always identified internally by its coordinate.

The subdomain becomes a presentation feature, not an administration
prerequisite. A magazine with no subdomain gets the same admin as a hosted
Unfold publication.

This folds the existing host-app magazine administration (wizard, index editing,
category/article assignment) into the Unfold bundle as its integral part, and
makes the article editor available inside a publication context.

## Mounts

| Mount | Publication resolved from | Subdomain required | Route condition |
|---|---|---|---|
| `https://<sub>.<base>/admin/…` | Host → `_unfold_site` → `UnfoldSite` | yes | `request.attributes.has('_unfold_site')` |
| `https://<base>/mag/{mag}/admin/…` | Route `{mag}` + current user pubkey | no | none |

Both mounts resolve to the same `PublicationContext` and dispatch to the same
controllers, templates, and form types. No controller may read the host or the
route parameter directly.

## PublicationContext

A bundle-owned value object, the single input to every admin controller.

```
PublicationContext {
    string   coordinate        // 30040:<ownerPubkey>:<dtag> — canonical key
    string   ownerPubkey       // hex
    string   dtag
    ?UnfoldSite site           // null on the coordinate mount
    ?string  appDataCoordinate
    Mount    mount             // SUBDOMAIN | COORDINATE
    string   adminPathPrefix   // '/admin' | '/mag/<dtag>/admin'
    ?string  publicUrl         // subdomain URL when one exists
}
```

Two resolvers implement one interface:

- `HostPublicationResolver` — reads `_unfold_site`, takes `coordinate` and
  `ownerPubkey` from the row (deriving `ownerPubkey` from the coordinate while
  the Phase 1 backfill is incomplete).
- `CoordinatePublicationResolver` — reads `{mag}` **and the authenticated user's
  hex pubkey**, resolves the pair to a coordinate, and attaches the `UnfoldSite`
  row if one happens to exist for that coordinate.

`adminPathPrefix` exists so templates can build links without knowing the mount.
All admin templates must route through it; hardcoded `/admin/…` paths break the
coordinate mount.

## Slug Resolution Is Not An Ownership Signal

`MagazineStructureService::findLatestIndexBySlug()` resolves a slug with **no
pubkey filter**:

```sql
SELECT * FROM event e WHERE e.kind = :kind AND e.d_tag = :slug
ORDER BY e.created_at DESC LIMIT 1
```

Across pubkeys this is last-writer-wins. Any pubkey can publish a `kind:30040`
with an existing d-tag and win the lookup.

For public `/mag/{mag}` reading this is pre-existing behaviour and out of scope
here. For an owner-scoped admin it is disqualifying: the access check would
compare the logged-in user against whichever pubkey won the race.

**Hard constraint:** the coordinate mount must never derive ownership from a
slug alone.

- Admin lookups use `(dtag, currentUserPubkey)` and resolve to
  `30040:<currentUserPubkey>:<dtag>`.
- Ownership is therefore structural, not a comparison — a user can only ever
  address their own publication, and the "owner mismatch" branch is unreachable
  on this mount.
- If no `kind:30040` exists for that pair, return 404, never fall back to
  another pubkey's event.

Note also that `Magazine.slug` carries `unique: true`, so the projection cannot
represent two magazines sharing a slug at all. That is a separate latent
projection bug; record it, do not fix it inside this work.

## Route Mounting Rules

The bundle currently declares every route with
`condition: "request.attributes.has('_unfold_site')"`, so it cannot serve
apex-domain routes at all. Two changes:

1. Split `Resources/config/routes.yaml` into a subdomain collection (keeps the
   condition) and an apex admin collection (no condition, prefixed
   `/mag/{mag}/admin`).
2. Both admin collections must be imported **before** the `unfold_site`
   catch-all. `unfold_site` is `/{path}` with `path: '.*'`; without ordering it
   swallows `/admin/*`, and `RouteMatcher` would then read `admin` as a category
   slug and return `PAGE_NOT_FOUND`.

`RouteMatcher` itself needs no change once ordering is correct, but it should
gain an explicit reserved-prefix guard so a category literally named `admin`
cannot shadow the admin shell.

## Access Rule

Extends `01-appdata-and-owner-admin.md`, unified across both mounts:

- Anonymous → redirect to login.
- Subdomain mount → compare the session pubkey (npub converted to hex) against
  `PublicationContext.ownerPubkey`; non-owners get access denied.
- Coordinate mount → ownership is structural per the constraint above.
- `ROLE_ADMIN` does **not** grant publication ownership on either mount. DN
  operators keep a separate, narrow moderation surface.

## Draft State

The magazine wizard is currently session-backed (`SESSION_KEY = 'mag_wizard'` in
`MagazineWizardController`). A single session-scoped draft cannot survive the
dual mount: a draft started on the apex domain is not readable on the subdomain
unless the session cookie is widened to `.<base-domain>` (open question Q7).

Replace it with a Redis-backed draft keyed by
`unfold:draft:<coordinate>` (or `<pubkey>:<dtag>` before first publish):

- Removes Q7 from the admin path entirely; cookie scope becomes a Phase 2b
  reader-interaction question only.
- Lets an owner resume a draft from either mount.
- Allows more than one publication draft per user, which the current single
  session key forbids.
- Needs an explicit TTL and an owner-initiated discard, replacing
  `mag_wizard_cancel`.

## Consolidation Map

What moves into the bundle admin, and what it replaces.

| Existing surface | File | Disposition |
|---|---|---|
| Magazine wizard (setup → categories → articles → review → subdomain → done) | `src/Controller/Newsroom/MagazineWizardController.php` | Move into bundle admin; re-scope from session+slug to `PublicationContext`; subdomain step becomes optional |
| Index add/remove article coordinates | `src/Controller/Administration/MagazineEditorController.php` | Move into bundle `/admin/content`; drop `ROLE_ADMIN` for owner scope |
| Magazine list / hide / unhide / delete / orphaned | `src/Controller/Administration/MagazineAdminController.php` | **Stays** platform moderation, `ROLE_ADMIN` |
| Subdomain ↔ coordinate CRUD | `src/Controller/Administration/UnfoldSiteController.php` | Stays for DN repair/diagnostics; owner-facing subdomain claim moves into bundle admin |
| Subdomain subscription billing | `src/Controller/Administration/PublicationSubdomainAdminController.php` | **Stays** platform billing, `ROLE_ADMIN` |
| Visitor analytics | `src/Controller/Administration/VisitorAnalyticsController.php` | Bundle admin gets a subdomain-filtered view per spec 04; platform-wide view stays |

The distinction to preserve: **publication ownership** is owner-scoped and lives
in the bundle; **platform moderation and billing** stay `ROLE_ADMIN` on the main
domain.

## Article Editor Integration

Decision: the standalone editor stays; the admin editor is the same code with a
publication context injected.

- `/article-editor/*` (`src/Controller/Editor/EditorController.php`) continues to
  serve context-free single-article authoring, unchanged.
- The admin mount adds `<adminPathPrefix>/articles/new` and
  `<adminPathPrefix>/articles/{slug}/edit`, rendering the same editor with a
  `PublicationContext` and a target category.
- On publish, the admin path performs two steps as one owner-visible action:
  publish the `kind:30023` article, then append its coordinate to the chosen
  category and publish the updated `kind:30040`.
- The second step must be resilient: if the article publishes but the index
  update fails, surface a retry that re-appends the already-published coordinate
  rather than re-publishing the article.

`EditorController` has no `kind:30040` awareness today, so this is new
integration work rather than a move. Keep the publication coupling in a thin
wrapper so the standalone editor keeps zero collection dependencies.

## Failure States

Additional to spec 04:

- Coordinate mount, no `kind:30040` for `(dtag, currentUserPubkey)` → 404.
- Coordinate mount, anonymous user → login redirect, never slug-only resolution.
- Subdomain mount, `UnfoldSite` row present but coordinate malformed → operator
  diagnostic screen, not a publication admin shell.
- Draft coordinate no longer matches the resolved publication → discard prompt,
  never silent overwrite.

## Test Coverage

- Unit: `PublicationContext` resolvers, including the
  `(dtag, currentUserPubkey)` scoping and the 404-not-fallback rule.
- Unit: `adminPathPrefix` link building for both mounts.
- Functional: `/admin` and `/mag/{mag}/admin` reach identical controllers and
  render equivalent shells.
- Functional: route ordering — `/admin` on a subdomain is not swallowed by
  `unfold_site`.
- Security: a pubkey that publishes a colliding d-tag cannot reach another
  pubkey's admin on either mount.
- Functional: article publish + index append, including the append-retry path.
