# Identity Bundle

`decent-newsroom/identity-bundle` is a reusable Symfony bundle for linking one host application user to one or more external identities.

The bundle's core job is account identity, not signing policy or publishing. It proves an incoming authentication request belongs to an external identity, resolves that identity to the host application's user through a small bridge contract, and stores durable identity links in `identity_user_link`.

## What It Owns

- Identity links between a host user owner id and external identities.
- Nostr HTTP authentication through NIP-98-style `Authorization: Nostr ...` login requests.
- Provider discovery through the `identity.provider` service tag.
- A Symfony authenticator and user provider that do not depend on `App\Entity\User`.
- Doctrine mapping for `DecentNewsroom\IdentityBundle\Entity\UserIdentityLink`.
- Transitional NIP-46 remote-signer compatibility classes used by existing host relay AUTH wiring.

## What It Does Not Own

- The host application's concrete user entity, roles, or profile data.
- Event persistence, relay publication, moderation, or article workflows.
- Browser-side NIP-07 signing.
- Relay gateway connection pooling.
- Nostr Connect routes in the current package split; those are owned by SigningBundle.

Those boundaries let the host add more providers, such as email OTP, passkeys, or OAuth, without making each provider understand the host's user table.

## Main Classes

| Area | Class |
|---|---|
| Bundle entry point | `DecentNewsroom\IdentityBundle\IdentityBundle` |
| Configuration | `DependencyInjection\Configuration`, `IdentityExtension` |
| Identity link entity | `Entity\UserIdentityLink` |
| Identity link repository | `Repository\UserIdentityLinkRepository` |
| Link management | `Service\IdentityLinkingService` |
| Nostr provider | `Security\IdentityProvider\NostrIdentityProvider` |
| Symfony authenticator | `Security\Authenticator\NostrAuthenticator` |
| Symfony user provider | `Security\IdentityUserProvider` |
| Signer strategy registry | `Service\NostrSignerStrategyRegistry` |
| NIP-46 session store | `Service\Nostr\Nip46SessionStore` |
| NIP-46 AUTH strategy | `Service\Nostr\RemoteBunkerSignerStrategy` |

## Public Contracts

- `IdentityOwnerInterface` - implemented by the host user object. Exposes a stable opaque owner id and Symfony's `UserInterface`.
- `UserRepositoryBridgeInterface` - host adapter that finds or creates users by provider/external id, loads by owner id, and loads by Symfony identifier.
- `IdentityProviderInterface` - one authentication method, responsible for proving a request belongs to one provider-specific external id.
- `SignerRelayProviderInterface` - transitional host adapter for signer relay URLs used by legacy IdentityBundle NIP-46 code.
- `Nip46AuthEventSignerInterface` - transitional host adapter for server-side NIP-42 AUTH signing through an existing NIP-46 transport.
- `NostrSignerStrategyInterface` - strategy contract for server-side Nostr signing methods registered with `identity.nostr_signer_strategy`.

## Configuration

```yaml
# config/packages/identity.yaml
identity:
    providers:
        - nostr
    email_otp:
        code_length: 6
        code_ttl_seconds: 600
        max_attempts_per_hour: 5
```

Only the `nostr` provider is implemented in this package today. The email OTP options are reserved for the next provider implementation.

The extension also prepends Doctrine ORM mapping for the bundle entity, so the host does not need to manually register the entity path.

## Host Wiring

Install the package from the local path repository during extraction work:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/identity-bundle",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "decent-newsroom/identity-bundle": "@dev"
    }
}
```

Register the bundle if Symfony Flex has not done so:

```php
use DecentNewsroom\IdentityBundle\IdentityBundle;

return [
    IdentityBundle::class => ['all' => true],
];
```

Provide a bridge from IdentityBundle to the host user repository:

```yaml
services:
    DecentNewsroom\IdentityBundle\Contract\UserRepositoryBridgeInterface:
        alias: App\Security\AppIdentityBridge
```

The host user class must implement `IdentityOwnerInterface`, usually by returning its primary key as a string from `getIdentityOwnerId()`.

Wire the Nostr authenticator into the host firewall:

```yaml
security:
    firewalls:
        main:
            custom_authenticators:
                - DecentNewsroom\IdentityBundle\Security\Authenticator\NostrAuthenticator
            entry_point: DecentNewsroom\IdentityBundle\Security\Authenticator\NostrAuthenticator
```

Add the bundle migration namespace to the host Doctrine Migrations config:

```yaml
doctrine_migrations:
    migrations_paths:
        IdentityBundleMigrations: '%kernel.project_dir%/vendor/decent-newsroom/identity-bundle/src/Migrations'
```

The package route file is intentionally empty at the moment. Nostr Connect QR and session routes live in SigningBundle in the current package layout.

## Nostr Login Flow

1. The browser posts to `/login` with an `Authorization` header beginning with `Nostr `.
2. `NostrIdentityProvider` decodes the base64 event, validates event shape, and delegates NIP-98 validation to `nostr-kernel-bundle`.
3. The provider returns the authenticated hex pubkey as the `nostr` external id.
4. `NostrAuthenticator` calls `UserRepositoryBridgeInterface::findOrCreateByIdentity()`.
5. The host bridge returns the concrete user, creating and linking it if this is a first-time login.

Successful JSON login requests receive `{"message":"Authentication Successful"}`. Authentication failures and JSON entry-point responses return HTTP 401 JSON.

## Identity Links

`UserIdentityLink` stores:

- `owner_id` - opaque id for the host user;
- `provider` - provider machine name such as `nostr`;
- `external_id` - provider-specific unique identifier, such as a hex pubkey;
- optional label, metadata, verified timestamp, created timestamp, and last-use timestamp.

The `(provider, external_id)` pair is unique, so one external identity cannot be claimed by two host users.

## Testing

Focused package tests can run from the project root:

```bash
vendor/bin/phpunit packages/identity-bundle/tests
```

Project-level validation should still run inside Docker when the app container is available:

```bash
docker compose exec php bin/console lint:container
docker compose exec php bin/phpunit
```