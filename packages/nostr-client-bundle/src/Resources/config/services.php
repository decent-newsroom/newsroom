<?php

declare(strict_types=1);

use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use DecentNewsroom\NostrClientBundle\Infrastructure\Innis\InnisNostrClientFactory;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->private();

    $services->set(InnisNostrClientFactory::class)
        ->arg('$connectionTimeoutSeconds', param('nostr_client.connection_timeout_seconds'))
        ->arg('$autoReconnect', param('nostr_client.auto_reconnect'))
        ->arg('$reconnectInitialDelayMs', param('nostr_client.reconnect_initial_delay_ms'))
        ->arg('$reconnectMaxDelayMs', param('nostr_client.reconnect_max_delay_ms'))
        ->arg('$reconnectMaxAttempts', param('nostr_client.reconnect_max_attempts'))
        ->arg('$userAgent', param('nostr_client.user_agent'));

    $services->alias(NostrClientFactoryInterface::class, InnisNostrClientFactory::class);

    $services->set(NostrClientInterface::class)
        ->factory([service(InnisNostrClientFactory::class), 'create']);

    $services->set(RelayHealthCheckerInterface::class)
        ->factory([service(InnisNostrClientFactory::class), 'createHealthChecker']);

    $services->set(ConnectionConfig::class)
        ->factory([service(InnisNostrClientFactory::class), 'createDefaultConnectionConfig']);
};
