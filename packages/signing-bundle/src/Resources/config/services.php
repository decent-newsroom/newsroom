<?php

declare(strict_types=1);

use DecentNewsroom\SigningBundle\Contract\CurrentSubjectPubkeyResolverInterface;
use DecentNewsroom\SigningBundle\Contract\Nip46AuthEventSignerInterface;
use DecentNewsroom\SigningBundle\Contract\Nip46EventSignerInterface;
use DecentNewsroom\SigningBundle\Contract\NostrEventSignerInterface;
use DecentNewsroom\SigningBundle\Contract\RelayAuthSignerInterface;
use DecentNewsroom\SigningBundle\Contract\RemoteSignerSessionStoreInterface;
use DecentNewsroom\SigningBundle\Controller\NostrConnectController;
use DecentNewsroom\SigningBundle\Controller\RemoteSignerSessionController;
use DecentNewsroom\SigningBundle\Service\Nostr\Nip46EventSigner;
use DecentNewsroom\SigningBundle\Service\Nostr\Nip46SessionStore;
use DecentNewsroom\SigningBundle\Service\Nostr\NostrConnectUriFactory;
use DecentNewsroom\SigningBundle\Service\Nostr\NostrSignerStrategyRegistry;
use DecentNewsroom\SigningBundle\Service\Nostr\RelayAuthEventFactory;
use DecentNewsroom\SigningBundle\Service\Nostr\RemoteBunkerSignerStrategy;
use DecentNewsroom\SigningBundle\Storage\RedisRemoteSignerSessionStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(RedisRemoteSignerSessionStore::class)
        ->arg('$redis', service('Redis'))
        ->arg('$encryptionKey', param('signing.nip46.encryption_key'))
        ->arg('$ttlSeconds', param('signing.nip46.session_ttl_seconds'))
        ->arg('$keyPrefix', param('signing.nip46.redis_prefix'));

    $services->alias(RemoteSignerSessionStoreInterface::class, RedisRemoteSignerSessionStore::class);

    $services->set(Nip46SessionStore::class)
        ->arg('$store', service(RemoteSignerSessionStoreInterface::class))
        ->arg('$ttlSeconds', param('signing.nip46.session_ttl_seconds'));

    $services->set(NostrConnectUriFactory::class)
        ->arg('$appName', param('signing.app_name'))
        ->arg('$configuredAppUrl', param('signing.app_url'))
        ->arg('$requestedPermissions', param('signing.nostr_connect.requested_permissions'));

    $services->set(NostrConnectController::class)
        ->tag('controller.service_arguments');

    $services->set(RemoteSignerSessionController::class)
        ->arg('$subjectPubkeys', service(CurrentSubjectPubkeyResolverInterface::class))
        ->tag('controller.service_arguments');

    $services->set(RelayAuthEventFactory::class);

    $services->set(Nip46EventSigner::class)
        ->arg('$defaultTimeoutSeconds', param('signing.nip46.request_timeout_seconds'));

    $services->alias(Nip46EventSignerInterface::class, Nip46EventSigner::class);
    $services->alias(Nip46AuthEventSignerInterface::class, Nip46EventSigner::class);

    $services->set(RemoteBunkerSignerStrategy::class)
        ->arg('$eventSigner', service(Nip46EventSignerInterface::class))
        ->tag('signing.nostr_signer_strategy');

    $services->alias(NostrEventSignerInterface::class, RemoteBunkerSignerStrategy::class);
    $services->alias(RelayAuthSignerInterface::class, RemoteBunkerSignerStrategy::class);

    $services->set(NostrSignerStrategyRegistry::class)
        ->arg('$strategies', tagged_iterator('signing.nostr_signer_strategy'));
};
