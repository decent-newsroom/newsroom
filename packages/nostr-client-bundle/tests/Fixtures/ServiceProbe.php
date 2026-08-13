<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrClientBundle\Tests\Fixtures;

use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;

final readonly class ServiceProbe
{
    public function __construct(
        public NostrClientFactoryInterface $factory,
        public NostrClientInterface $client,
        public RelayHealthCheckerInterface $healthChecker,
        public ConnectionConfig $connectionConfig,
    ) {
    }
}
