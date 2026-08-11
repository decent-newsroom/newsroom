# Nostr Kernel Bundle

Reusable Symfony integration for low-level Nostr event, identity, coordinate,
validation, signature, and NIP-19 concerns.

The bundle does not provide relay transport, persistence, application
authentication, projections, or UI. Those concerns remain owned by the host
application.

Register `DecentNewsroom\NostrKernelBundle\NostrKernelBundle` in the host
application's `config/bundles.php` and configure the `nostr_kernel` section as
needed.
