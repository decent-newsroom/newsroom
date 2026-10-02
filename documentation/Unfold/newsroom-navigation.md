# Newsroom Unfold navigation

The Newsroom sidebar shows an **Unfold** section after Publications for signed-in owners with active, unexpired publication-subdomain reservations.

Each item uses the latest kind `30040` publication title for the reserved coordinate and links to that publication's main-domain Unfold administration overview. If the index has not yet been stored locally, the publication identifier is shown instead.

Reservations are queried by the authenticated owner's `npub`; subscriptions owned by other users, pending subscriptions, and expired subscriptions are not listed.
