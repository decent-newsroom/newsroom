<?php

declare(strict_types=1);

use DecentNewsroom\RelayGatewayBundle\Command\RelayGatewayCommand;
use DecentNewsroom\RelayGatewayBundle\Contract\AuthChallengeSignerInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayActivityRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayFilterStatsRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayHealthRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\RelayUrlResolverInterface;
use DecentNewsroom\RelayGatewayBundle\Service\NullAuthChallengeSigner;
use DecentNewsroom\RelayGatewayBundle\Service\NullGatewayRecorder;
use DecentNewsroom\RelayGatewayBundle\Service\PassthroughRelayUrlResolver;
use DecentNewsroom\RelayGatewayBundle\Service\RelayGatewayClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->private();

    $services->set(NullGatewayRecorder::class);
    $services->set(NullAuthChallengeSigner::class);
    $services->set(PassthroughRelayUrlResolver::class);

    $services->alias(RelayUrlResolverInterface::class, PassthroughRelayUrlResolver::class);
    $services->alias(AuthChallengeSignerInterface::class, NullAuthChallengeSigner::class);
    $services->alias(GatewayHealthRecorderInterface::class, NullGatewayRecorder::class);
    $services->alias(GatewayActivityRecorderInterface::class, NullGatewayRecorder::class);
    $services->alias(GatewayFilterStatsRecorderInterface::class, NullGatewayRecorder::class);

    $services->set(RelayGatewayClient::class)
        ->arg('$redis', service('Redis'));

    $services->set(RelayGatewayCommand::class)
        ->arg('$redis', service('Redis'))
        ->arg('$streamBlockMs', param('relay_gateway.stream_block_ms'))
        ->arg('$responseTtlSeconds', param('relay_gateway.response_ttl_seconds'))
        ->arg('$heartbeatTtlSeconds', param('relay_gateway.heartbeat_ttl_seconds'))
        ->arg('$heartbeatIntervalSeconds', param('relay_gateway.heartbeat_interval_seconds'))
        ->arg('$authTimeoutSeconds', param('relay_gateway.auth_timeout_seconds'));
};
