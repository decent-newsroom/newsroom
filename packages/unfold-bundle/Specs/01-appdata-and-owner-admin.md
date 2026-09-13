# Local Setup And Owner Administration

Status: local setup and persistent theme settings are the first implementation
slice. Owner administration and the additional settings below remain planned.
The filename is retained for existing references; AppData-first setup is superseded.

## Goal And Identity

An Unfold is permanently identified by exactly one root magazine coordinate,
`30040:<owner_pubkey>:<dtag>`. The full coordinate is immutable. Descendant
indexes remain part of that root publication; there is no multi-root model.

Creating, configuring, editing, activating, or rendering an Unfold must not
require fetching, signing, or publishing a kind `30078` AppData event. Owner
administration must not introduce that prerequisite later.

## Configuration Boundaries

- Local publication settings are keyed by the root coordinate. The current
  implementation stores the selected theme. Existing sites without settings
  retain the default theme.
- The root index and its descendants remain the sources for publication content,
  title, description, and navigation. Settings do not duplicate those fields.
- `UnfoldSite` maps a subdomain to the root coordinate. Hosting and subscription
  billing remain separate from publication settings.
- Bundle-owned settings objects and persistence contracts keep host storage
  details outside the bundle. Runtime `SiteConfig` resolves the index and local
  settings together.
- Future audience selections, payment-target references, about links, and access
  service configuration will be added when their actual workflows require them.
  Referenced signed events are authoritative for their own contents; local
  selections are authoritative for which references are selected.

Refreshing an index or referenced event must not silently overwrite unrelated
local settings. A setup or settings save does not publish an umbrella event.

## Current Hosted Setup

1. An operator submits the administration form with a subdomain, root coordinate,
   and theme using an ordinary CSRF-protected request.
2. A shared setup service validates the input, stores coordinate-keyed settings
   and the hosting mapping, and invalidates relevant cached site configuration.
3. Subscription activation uses the same service, preserving existing settings
   when the activation does not specify new values.
4. Editing settings keeps the root coordinate fixed. Public rendering resolves
   that coordinate and applies its stored theme.

No signer or relay publication is involved. Legacy AppData loading remains an
isolated compatibility path, not a setup source of truth. Existing coordinate
mappings and URLs remain valid.

## Planned Owner Administration

Spec `08-unified-publication-admin.md` defines both mounts:

- On the subdomain: `/admin` and its child pages.
- On the main domain: `/mag/{mag}/admin` and equivalent child pages.

Planned pages include settings, audiences, payment targets, content, and
analytics. Use `/admin/settings`, not a mandatory AppData-signing page.

Access rules:

- Anonymous visitors are redirected to login.
- On a subdomain, the authenticated hex pubkey must equal the owner pubkey in
  the root coordinate. Non-owners receive access denied.
- Coordinate-mount lookups use `(authenticated pubkey, d-tag)` exclusively.
  Never infer ownership from a slug-only lookup.
- Platform administrators retain separate operator repair/diagnostic screens;
  that role does not make them owners or authorize signing as owners.
- Signing is required for changes to Nostr events, through the owner's browser
  signer, but not for local settings.

Both onboarding orders remain planned: publication-first or subdomain-first.
A subdomain-first reservation is a hosting draft, not an Unfold without an
identity; attach one root coordinate before activation/public rendering. Do not
introduce nullable-root public sites in this setup slice.

Readiness is capability-specific: publication configured, hosting active, and
access integration ready are separate states. Missing audiences or payment
services must not block ordinary publication management.

## Deferred Portable Definition

Portability remains a goal. After scoped publishing, authorized reads, audiences,
and payment integration establish the real relationships, design a custom event
that can reconstruct an Unfold on a clean host. Do not allocate a kind or freeze
its schema in this refactor. It will describe the same single root identity.

Import/export, reference resolution, revision handling, and conflict rules must
be specified then. Exclude receipts, access tokens, credentials, and DN billing
state. Imported service references cannot establish operator trust by themselves.
Publishing the definition must remain independent of saving settings or running
the site.
