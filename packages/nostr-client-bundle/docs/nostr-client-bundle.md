# Nostr Client Bundle

## What

`packages/nostr-client-bundle` (`decent-newsroom/nostr-client-bundle`) is a
self-contained Symfony bundle (`DecentNewsroom\NostrClientBundle`) that wires
[`innis/nostr-client`](https://packagist.org/packages/innis/nostr-client) —
an AMPHP-based async WebSocket client for the Nostr protocol — into the
application's dependency injection container.

It replaces the previous hand-rolled `config/services.yaml` wiring for
`Innis\Nostr\Client\Application\Port\NostrClientInterface` (a single
hard-coded factory call with no configuration) with a proper bundle:

- `nostr_client` configuration (`config/packages/nostr_client.yaml`) controls
  connection timeout, auto-reconnect behaviour, reconnect delays/attempts,
  and the client's user agent.
- `DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface` is
  a bundle-owned seam for creating clients/health-checkers, so application
  code can depend on a stable contract instead of the vendor's static
  factory directly.
- The following services are registered and autowirable:
  - `Innis\Nostr\Client\Application\Port\NostrClientInterface`
  - `Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface`
  - `Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig` (the default,
    config-derived connection configuration)
  - `DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface`

`decent-newsroom/expression-bundle`'s `InnisRelayEventClient` (which depends
on `NostrClientInterface`) now receives its client from this bundle instead
of the application's manual service definition.

## Why

`innis/nostr-client` was already a direct Composer dependency of both the
main application and `expression-bundle`, wired ad hoc via a single
`factory:` entry in `config/services.yaml` with no way to configure
connection/reconnect behaviour and no stable app-facing contract. Wrapping
it in a proper bundle (mirroring the existing `nostr-kernel-bundle` pattern
for `innis/nostr-core`) makes the wiring configurable, testable in
isolation (see `packages/nostr-client-bundle/tests`), and reusable by future
consumers without duplicating factory boilerplate.

## Scope and non-goals

This bundle wraps `innis/nostr-client` for DI consumption. The relay gateway now
uses it through `decent-newsroom/relay-gateway-bundle`, while the request-time
fallback relay pool and several legacy utilities still use `swentel/nostr-php`.

Current split:

- `DecentNewsroom\RelayGatewayBundle\Command\RelayGatewayCommand` owns the
  long-running gateway process and creates clients through this bundle.
- `App\Service\Nostr\RelayGatewayClient` remains as a compatibility subclass
  of the bundle client for existing host type hints.
- `App\Service\Nostr\NostrRelayPool` and `Util\NostrPhp\TweakedRequest` still
  provide direct fallback relay access when the gateway is disabled.
## Configuration

```yaml
# config/packages/nostr_client.yaml
nostr_client:
    connection_timeout_seconds: 10
    auto_reconnect: true
    reconnect_initial_delay_ms: 500
    reconnect_max_delay_ms: 60000
    reconnect_max_attempts: 0
    user_agent: null
```

## Usage

```php
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

final class ExampleService
{
    public function __construct(private readonly NostrClientInterface $client) {}

    public function connectExample(): void
    {
        $this->client->connect(RelayUrl::fromString('wss://relay.example.com'));
        // ...subscribe/publish, see innis/nostr-client README.
        $this->client->close();
    }
}
```

## Registration

- Composer path repository: `packages/nostr-client-bundle` (symlinked, `@dev`)
  in the root `composer.json`.
- Bundle class registered in `config/bundles.php`:
  `DecentNewsroom\NostrClientBundle\NostrClientBundle::class => ['all' => true]`.


