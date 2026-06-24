<?php

declare(strict_types=1);

use DecentNewsroom\NostrProjectionBundle\Application\Projection\DispatchProjection;
use DecentNewsroom\NostrProjectionBundle\Contract\Projection\ProjectionDispatcherInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Projection\ProjectorInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\CurrentRecordStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\EventReferenceStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\RawEventStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Repository\DoctrineCurrentRecordStore;
use DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Repository\DoctrineEventReferenceStore;
use DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Repository\DoctrineRawEventStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services
        ->load('DecentNewsroom\\NostrProjectionBundle\\', '../../')
        ->exclude([
            '../../Domain/',
            '../../DependencyInjection/',
            '../../NostrProjectionBundle.php',
        ]);

    $services
        ->instanceof(ProjectorInterface::class)
        ->tag('nostr_projection.projector');

    $services->alias(RawEventStoreInterface::class, DoctrineRawEventStore::class);
    $services->alias(CurrentRecordStoreInterface::class, DoctrineCurrentRecordStore::class);
    $services->alias(EventReferenceStoreInterface::class, DoctrineEventReferenceStore::class);

    $services
        ->set(DispatchProjection::class)
        ->arg('$projectors', tagged_iterator('nostr_projection.projector'));

    $services->alias(ProjectionDispatcherInterface::class, DispatchProjection::class);
};
