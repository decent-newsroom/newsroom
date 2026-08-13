<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrClientBundle\Infrastructure\Innis;

use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Psr\Log\LoggerInterface;

/**
 * DI-friendly wrapper around the static {@see NostrClientFactory} shipped by
 * innis/nostr-client, so the AMPHP-based client can be created from Symfony
 * services with a logger and a configuration-driven default connection setup.
 */
final readonly class InnisNostrClientFactory implements NostrClientFactoryInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private int $connectionTimeoutSeconds = 10,
        private bool $autoReconnect = true,
        private int $reconnectInitialDelayMs = 500,
        private int $reconnectMaxDelayMs = 60000,
        private int $reconnectMaxAttempts = 0,
        private ?string $userAgent = null,
    ) {
    }

    public function create(): NostrClientInterface
    {
        return NostrClientFactory::create($this->logger);
    }

    public function createHealthChecker(): RelayHealthCheckerInterface
    {
        return NostrClientFactory::createHealthChecker($this->logger);
    }

    public function createDefaultConnectionConfig(): ConnectionConfig
    {
        $config = new ConnectionConfig(
            connectionTimeoutSeconds: $this->connectionTimeoutSeconds,
            autoReconnect: $this->autoReconnect,
            reconnectInitialDelayMs: $this->reconnectInitialDelayMs,
            reconnectMaxDelayMs: $this->reconnectMaxDelayMs,
            reconnectMaxAttempts: $this->reconnectMaxAttempts,
        );

        if ($this->userAgent !== null) {
            $config = $config->withUserAgent($this->userAgent);
        }

        return $config;
    }
}
