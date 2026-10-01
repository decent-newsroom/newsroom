# Unfold Publication Onboarding Drafts

Publication onboarding begins on the main domain at `/magazine/onboarding`.
The initial flow saves publication basics locally before a root index is signed
or a hosting claim is attached.

In Newsroom, the bundle fallback is overridden at
`templates/bundles/UnfoldBundle/onboarding/basics.html.twig` so onboarding uses
the shared application shell, navigation, and publication-admin styling. The
bundle retains its plain fallback for standalone hosts.

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

The onboarding flow signs and publishes the initial immutable root `kind:30040`
index from a server-prepared payload. The server verifies the signature, exact
owner and d-tag, and every signed tag before projecting it. Once the root is
committed, the selected theme is stored for its canonical coordinate and the
draft moves to its canonical Redis key.

Category/article management, subdomain attachment, and legacy wizard retirement
remain later slices.
