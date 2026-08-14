# Signing Bundle

Reusable Symfony bundle for Nostr signing orchestration in Decent Newsroom.

The bundle answers one question:

> Can the app obtain a valid Nostr signature for this pubkey, and by which method?

It does not authenticate accounts, persist published events, decide publication
policy, or own long-lived relay gateway connections. Those remain host or
neighbor-bundle responsibilities.

## What It Owns

- Nostr Connect QR generation at `/nostr-connect/qr`.
- Post-auth remote signer session registration at `/api/nostr-connect/session`.
- Encrypted Redis storage for NIP-46 remote signer sessions.
- Server-side NIP-46 `sign_event` requests through `nostr-client-bundle`.
- Validation of signed events returned by a remote signer.
- Relay AUTH signing for kind `22242` events.
- Signer strategy discovery through the `signing.nostr_signer_strategy` tag.

## What It Does Not Own

- User authentication, identity links, or Symfony user lookup.
- Browser extension signing for already signed publish requests.
- Mercure browser fallback for relay AUTH.
- Event persistence, moderation, policy checks, or relay publication.
- Relay gateway connection pooling and reconnect policy.

Those boundaries let NIP-07 extension flows and NIP-46 bunker flows coexist:
extension users can keep posting already signed events, while bunker users can
store a server-side remote signer session for background signing and relay AUTH.

## Main Classes

| Area | Class |
|---|---|
| Bundle entry point | `DecentNewsroom\SigningBundle\SigningBundle` |
| Configuration | `DependencyInjection\Configuration`, `SigningExtension` |
| QR endpoint | `Controller\NostrConnectController` |
| Session endpoint | `Controller\RemoteSignerSessionController` |
| Session value object | `Dto\RemoteSignerSession` |
| Redis session storage | `Storage\RedisRemoteSignerSessionStore` |
| Compatibility session facade | `Service\Nostr\Nip46SessionStore` |
| NIP-46 RPC signer | `Service\Nostr\Nip46EventSigner` |
| NIP-46 response handler | `Service\Nostr\Nip46ResponseHandler` |
| Relay AUTH event builder | `Service\Nostr\RelayAuthEventFactory` |
| Bunker strategy | `Service\Nostr\RemoteBunkerSignerStrategy` |
| Strategy registry | `Service\Nostr\NostrSignerStrategyRegistry` |

## Public Contracts

- `CurrentSubjectPubkeyResolverInterface` - host adapter that resolves the
  authenticated user's hex pubkey for post-auth session storage.
- `SignerRelayProviderInterface` - host adapter that supplies signer relay URLs.
- `RemoteSignerSessionStoreInterface` - storage boundary for encrypted remote
  signer sessions.
- `Nip46EventSignerInterface` - low-level NIP-46 `sign_event` signer.
- `Nip46AuthEventSignerInterface` - compatibility contract for signing relay
  AUTH events through NIP-46.
- `NostrEventSignerInterface` - generic unsigned event intent to signed event.
- `RelayAuthSignerInterface` - relay AUTH support check and kind `22242`
  signing.
- `NostrSignerStrategyInterface` - method-based signer strategy discovery.

## Configuration

```yaml
# config/packages/signing.yaml
signing:
    app_name: 'Decent Newsroom'
    nostr_connect:
        requested_permissions:
            - 'sign_event:27235'
            - 'sign_event:22242'
            - 'get_public_key'
    nip46:
        session_ttl_seconds: 28800
        request_timeout_seconds: 15
        redis_prefix: 'nip46_session:'
        encryption_key: '%env(APP_ENCRYPTION_KEY)%'
```

## Host Wiring

The host application wires local policy and account context into the bundle:

```yaml
DecentNewsroom\SigningBundle\Contract\SignerRelayProviderInterface:
    alias: App\Service\Nostr\RelayRegistry

DecentNewsroom\SigningBundle\Contract\CurrentSubjectPubkeyResolverInterface:
    alias: App\Signing\AppCurrentSubjectPubkeyResolver
```

Routes are imported from the package:

```yaml
signing_bundle:
    resource: '../../packages/signing-bundle/src/Resources/config/routes.yaml'
```

## Supported Flows

### Extension Publishing

Browser JavaScript signs events with NIP-07 and posts already signed events to
the backend. SigningBundle does not sign in this path. The host still verifies
the event id, signature, pubkey, and account relationship before persistence or
relay publishing.

### Extension Relay AUTH

RelayGatewayBundle asks the host for a signed kind `22242` AUTH event. The host
tries `RelayAuthSignerInterface` first. If no NIP-46 session exists, the host may
fall back to Mercure and browser-side extension signing.

### Bunker Publishing And Relay AUTH

After pairing, the browser posts NIP-46 session material to
`/api/nostr-connect/session`. The bundle stores the encrypted session under the
authenticated subject pubkey. Later, server-side publish intent or relay AUTH can
use `RemoteBunkerSignerStrategy` to reach the bunker directly.

The signer validates returned events before handing them back:

- returned pubkey must match the requested subject pubkey;
- signed event fields must match the unsigned intent;
- event id and signature must validate through `innis/nostr-core`.

## Testing

Focused package tests can run without booting the host kernel:

```bash
vendor/bin/phpunit packages/signing-bundle/tests/Service/Nostr
```

Project-level validation should still run inside Docker when the app container
is available:

```bash
docker compose exec php bin/console lint:container
docker compose exec php bin/phpunit
```
