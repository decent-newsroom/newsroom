# Nostr Client Bundle

Reusable Symfony integration wrapping [`innis/nostr-client`](https://packagist.org/packages/innis/nostr-client),
an AMPHP-based async WebSocket client for the Nostr protocol.

The bundle wires the vendor library's static factory into DI-managed services
so the host application can inject a ready-to-use relay client instead of
constructing it by hand:

- `Innis\Nostr\Client\Application\Port\NostrClientInterface` — connect,
  subscribe, publish, health-check against one or more relays.
- `Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface` — standalone
  relay health checks without an active connection.
- `Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig` — the default
  connection configuration derived from bundle config.
- `DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface` — a
  stable, bundle-owned seam for creating clients/health-checkers, so call
  sites don't depend on the vendor factory directly.

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
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

final class ExampleService
{
    public function __construct(private readonly NostrClientInterface $client) {}

    public function fetchRecent(string $relayUrl): void
    {
        $relay = RelayUrl::fromString($relayUrl);
        $this->client->connect($relay);
        // ...subscribe/publish as needed, see innis/nostr-client README.
        $this->client->close();
    }
}
```

## Scope and migration path

This bundle wraps `innis/nostr-client` for DI consumption. It is now the base
transport for `decent-newsroom/relay-gateway-bundle`, whose command owns the
long-running Redis Stream gateway process.

The remaining split is intentional:

- `DecentNewsroom\RelayGatewayBundle\Command\RelayGatewayCommand` uses this
  bundle for persistent gateway connections.
- `App\Service\Nostr\RelayGatewayClient` is a compatibility subclass of the
  extracted bundle client.
- `App\Service\Nostr\NostrRelayPool` and `TweakedRequest` still provide direct
  fallback relay access when the gateway is disabled.
