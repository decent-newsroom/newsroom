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

This bundle currently only wraps `innis/nostr-client` for DI consumption; it
does **not** change any existing relay code in the main application. The
app's synchronous, `swentel/nostr-php`-based relay handling
(`App\Service\Nostr\NostrRelayPool`, `GatewayConnection`,
`RelayGatewayCommand`, `TweakedRequest`) is unaffected.

The bundle is the foundation for an eventual migration:

- `swentel/nostr-php` (`swentel\nostr\Relay\Relay` + `WebSocket\Client`) is a
  synchronous, blocking WebSocket client used directly in request workers and
  in the persistent relay-gateway connection pool.
- `innis/nostr-client` is AMPHP-based and async/fiber-driven, which is a
  better fit for the long-running `relay-gateway` and `worker-relay` Docker
  services than for short-lived synchronous request handling.

Replacing swentel/hand-rolled WebSocket code with this bundle is a separate,
larger effort (primarily in `RelayGatewayCommand`'s connection pool) tracked
outside this change. See `documentation/Nostr/nostr-client-bundle.md` for
details.
