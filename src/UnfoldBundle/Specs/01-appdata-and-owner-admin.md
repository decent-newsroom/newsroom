# AppData And Owner Administration

## Goal

Move Unfold publication management from DN-admin-only setup to owner-managed setup. The owner of the root publication index signs the AppData event, and the Unfold bundle uses that signed event as the publication configuration source.

## AppData Event

AppData remains a NIP-78 `kind:30078` event. It must be signed by the publication owner, not by a DN admin account.

Required tags:

```json
["d", "<site-identifier>"]
["publication", "30040:<owner_pubkey>:<dtag>", "<relay_hint?>"]
["alt", "Unfold App Config"]
```

Optional tags:

```json
["about", "30023:<pubkey>:<dtag>", "<relay_hint?>"]
["audience", "38110:<owner_pubkey>:<dtag>", "<relay_hint?>"]
["payment_targets", "30133:<owner_pubkey>:<dtag>", "<relay_hint?>"]
["home_relay", "wss://relay.example.com"]
["theme", "default"]
```

`audience` is repeatable. `home_relay` is the only direct URL reference in AppData; all other linked publication resources are addressable event coordinates.

Legacy compatibility:

- Existing unmarked `["a", "30040:<owner_pubkey>:<dtag>"]` is accepted as a publication fallback.
- New admin flows publish the named `publication` tag only.
- If both are present, `publication` wins and `a` is ignored for publication resolution.

## Local Site State

`UnfoldSite` should conceptually contain:

- `subdomain`: existing unique subdomain key.
- `coordinate`: existing root publication coordinate fallback.
- `ownerPubkey`: hex pubkey that owns the publication.
- `appDataCoordinate`: optional `30078:<owner_pubkey>:<dtag>` coordinate for the signed Unfold AppData.
- timestamps as today.

Backfill rule:

- If `ownerPubkey` is missing, derive it from `coordinate`.
- If `appDataCoordinate` is missing, public rendering still works from `coordinate`.
- Owner admin is limited until AppData has been signed by the derived owner.

## Hosted Setup Flow

The paid hosted setup flow continues to reserve and activate a subdomain. After activation:

1. DN creates or retains the `UnfoldSite` shell for the selected publication coordinate.
2. The owner is redirected to the Unfold subdomain admin setup screen.
3. The browser signer builds AppData from the selected publication, theme, optional about article, audiences, payment descriptor, and home relay.
4. The backend accepts the signed AppData only if the signed event pubkey equals the owner pubkey from the publication coordinate.
5. The backend publishes the event to the owner's publishing relays and the configured home relay when present.
6. The `UnfoldSite.appDataCoordinate` is stored after successful validation.

DN admins may still create or repair `UnfoldSite` mappings from the main-domain admin area, but they do not sign AppData for publication owners.

## Owner Admin Access

Owner admin routes live on the Unfold subdomain:

- `/admin`
- `/admin/appdata`
- `/admin/audiences`
- `/admin/payment-targets`
- `/admin/content`
- `/admin/analytics`

Main-domain `/admin/*` remains DN platform administration.

Access rules:

- Anonymous visitors are redirected to login.
- Logged-in users are converted from `npub` to hex and compared with `UnfoldSite.ownerPubkey`.
- Matching owner pubkeys can access the publication admin.
- Non-owners receive access denied.
- DN admins retain operator access only for explicitly marked emergency/diagnostic screens and should not be treated as publication owners for signing.

## Implementation Notes

- Use the existing NIP-07/NIP-46 signing controller pattern rather than server-side private keys.
- AppData parser and builder should live in the Unfold bundle config layer, near `AppData` and `SiteConfigLoader`.
- AppData loading should prefer local database events when available and fall back to relays.
- Cache keys must include `appDataCoordinate` where AppData can change derived context.