# ExpressionBundle

`decent-newsroom/expression-bundle` is a reusable Symfony bundle for evaluating Nostr
feed expressions and spells.

It provides the expression parser, source resolvers, pipeline runner,
traversal operations, per-user result caching, and an optional authenticated
HTTP endpoint. Nostr transport is implemented with
[`innis/nostr-core`](https://github.com/innis/nostr-core) and
[`innis/nostr-client`](https://github.com/innis/nostr-client).

## Features

- Parses addressable kind `30880` expression events.
- Evaluates filter, set, sorting, slicing, distinct, and scoring operations.
- Supports graph traversal for threaded replies, comments, and publication
  indexes.
- Resolves event IDs, addresses, lists, pubkey lists, expressions, and kind
  `777` spells.
- Uses relays directly and can optionally supplement them with a generic local
  event store.
- Builds a runtime context from contacts, interests, and optional user relay
  data.
- Caches evaluated expression and spell results per user.
- Exposes `GET /api/feed/{naddr}` for authenticated evaluations.
- Keeps persistence, relay selection, and user-relay policy behind contracts so
  each host can provide its own implementation.

## Requirements

- PHP 8.3 or newer.
- Symfony 7.4 or newer.
- `innis/nostr-core` `^0.3.17`.
- `innis/nostr-client` `^0.1.7`.
- A PSR-6 cache pool and PSR-3 logger.

The package uses the `DecentNewsroom\ExpressionBundle` namespace. Its Composer
mapping is package-local and does not require the consuming application's
`App\` classes.

## Installation

Install the package from its Composer repository:

```bash
composer require decent-newsroom/expression-bundle
```

During local development, a Symfony host can consume the package through a
path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/expression-bundle",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "decent-newsroom/expression-bundle": "@dev"
    }
}
```

Register the bundle if Symfony Flex has not done so:

```php
use DecentNewsroom\ExpressionBundle\ExpressionBundle;

return [
    ExpressionBundle::class => ['all' => true],
];
```

Import its routes when the HTTP endpoint is needed:

```yaml
# config/routes/expression.yaml
expression_bundle:
    resource: '@ExpressionBundle/Resources/config/routes.yaml'
```

## Configuration

The bundle uses the `expression` configuration root:

```yaml
# config/packages/expression.yaml
expression:
    cache_ttl: 300
    max_depth: 5
    max_execution_time: 30
```

All values are optional. The defaults are 300 seconds for cached results, five
levels of nested expression traversal, and 30 seconds of evaluation time.

## Host integration

The bundle is deliberately storage- and policy-agnostic. A consuming
application must provide the relay contracts and may optionally provide a
generic local event store:

| Contract | Responsibility |
| --- | --- |
| `EventInterface` | Scalar access to a Nostr event. |
| `EventStoreInterface` | Optional generic local event lookup, filtering, and reference queries. |
| `RelaySelectorInterface` | Default, content, author, and local-relay selection plus URL normalization. |
| `RelayEventClientInterface` | Relay event fetching. The package includes `InnisRelayEventClient`. |
| `UserRelayProviderInterface` | Optional per-user relay resolution for runtime context and relay probes. |

The host should alias each required interface to its adapter:

```yaml
services:
    DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface:
        alias: App\Integration\ExpressionEventStore

    DecentNewsroom\ExpressionBundle\Contract\RelaySelectorInterface:
        alias: App\Integration\ExpressionRelaySelector

    DecentNewsroom\ExpressionBundle\Contract\RelayEventClientInterface:
        alias: DecentNewsroom\ExpressionBundle\Infrastructure\InnisRelayEventClient

    DecentNewsroom\ExpressionBundle\Contract\UserRelayProviderInterface:
        alias: App\Integration\ExpressionUserRelayProvider
```

`EventStoreInterface` and `UserRelayProviderInterface` are optional. Without a
local event store, all event, list, filter, and traversal lookups use relays.
Without a user relay provider, evaluations still work using the host's default
relay selection.

### Nostr client and bech32 services

`InnisRelayEventClient` uses the Innis client port. The host supplies the
client factory and logger:

```yaml
services:
    Innis\Nostr\Client\Application\Port\NostrClientInterface:
        factory: ['Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory', 'create']
        arguments:
            - '@logger'

    Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface:
        class: Innis\Nostr\Core\Infrastructure\Adapter\Bech32EncoderAdapter
```

Relay communication is read-only in this bundle. The built-in client connects
to the relay URLs selected by `RelaySelectorInterface`, builds Innis filters,
subscribes, and converts received events to the package `EventInterface`
representation.

## Caching

`ExpressionService` exposes cached and uncached evaluation methods:

- `evaluate()` and `evaluateSpell()`
- `evaluateCached()` and `evaluateSpellCached()`
- `getCachedResults()` and `getCachedSpellResults()`

The package owns cache-key generation and result caching. By default,
`FeedCacheService` uses Symfony's `cache.app` pool. Hosts can provide a
dedicated pool:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            expression.cache:
                adapter: cache.adapter.redis
                provider: Redis
                default_lifetime: 300
```

```yaml
# config/services.yaml
services:
    DecentNewsroom\ExpressionBundle\Service\FeedCacheService:
        arguments:
            $cache: '@expression.cache'
            $expressionCacheTtl: '%expression.cache_ttl%'
```

Cache keys include the expression coordinate and publication timestamp, the
user pubkey, contacts, and interests. Republishing an expression therefore
does not reuse the previous result cache.

## HTTP API

The optional authenticated endpoint is:

```text
GET /api/feed/{naddr}?offset=0&limit=50
```

It requires `ROLE_USER`, decodes the Nostr address, evaluates the expression
for the authenticated user, and returns:

```json
{
    "expression": "30880:pubkey:identifier",
    "count": 1,
    "offset": 0,
    "limit": 50,
    "events": []
}
```

The response limit is capped at 500 events.

## Evaluation flow

1. `ExpressionService` creates a user runtime context.
2. The parser converts the expression or spell into a pipeline.
3. `EventResolver` queries the optional generic local store and relays.
4. The runner applies operations and traversal rules.
5. The final `NormalizedItem[]` result is returned or cached.

The runtime context can include:

- kind `3` contacts;
- kind `10015` interests;
- optional kind `10002` user read relays.

## Testing

Install the package dependencies and run its package-owned test suite:

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

The package tests use `ArrayEvent` and contract mocks, so they do not require a
Doctrine entity or a newsroom database.

## Scope and limitations

This package does not provide:

- a database implementation;
- a global relay registry or user relay-list policy;
- relay publishing, signing, or NIP-42 authentication;
- application authentication or user management.

Those concerns belong to the consuming Symfony host and are connected through
the package contracts and service configuration.
