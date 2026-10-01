# Unfold Publication Onboarding Drafts

Publication onboarding begins on the main domain at `/magazine/onboarding`.
The initial flow saves publication basics locally before a root index is signed
or a hosting claim is attached.

## Ownership and storage

Each draft belongs to one normalized owner pubkey and d-tag. Its Redis key is
`unfold:draft:<owner-pubkey>:<dtag>`, with a one-hour TTL that is renewed every
time the owner saves it. The bundle owns the validated scalar draft shape; the
Newsroom Redis adapter owns persistence and expiry.

Drafts do not use the Symfony session. This avoids the old `mag_wizard` limit of
one draft per browser session and keeps unpublished state separate from the
legacy wizard while the replacement is introduced.

After a verified root `kind:30040` is published, a later publishing slice moves
the draft atomically to its canonical coordinate key. The move refuses to
overwrite an existing canonical draft and preserves the TTL.

## Access behavior

The onboarding routes use the same publication-admin identity contract and login
continuation as existing publication administration. They are private and
uncacheable. The authenticated pubkey, not a submitted field or slug lookup,
determines draft ownership.

The d-tag is immutable within an existing draft. Starting a different d-tag
creates a separate owner-scoped draft. There is no unpublished subdomain mount:
subdomain administration starts only after a published root coordinate has a
valid hosting mapping.

## Current scope

The first slice saves title, summary, image URL, language, tags, and theme. It
does not yet sign or publish an index, manage categories or articles, attach a
subdomain, or retire the legacy magazine wizard.
