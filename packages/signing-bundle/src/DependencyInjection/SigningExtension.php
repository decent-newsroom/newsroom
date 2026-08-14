<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class SigningExtension extends Extension
{
    /** @param array<string, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{app_name: string, app_url: ?string, nostr_connect: array{requested_permissions: list<string>}, nip46: array{session_ttl_seconds: int, request_timeout_seconds: int, redis_prefix: string, encryption_key: string}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('signing.app_name', $config['app_name']);
        $container->setParameter('signing.app_url', $config['app_url']);
        $container->setParameter('signing.nostr_connect.requested_permissions', $config['nostr_connect']['requested_permissions']);
        $container->setParameter('signing.nip46.session_ttl_seconds', $config['nip46']['session_ttl_seconds']);
        $container->setParameter('signing.nip46.request_timeout_seconds', $config['nip46']['request_timeout_seconds']);
        $container->setParameter('signing.nip46.redis_prefix', $config['nip46']['redis_prefix']);
        $container->setParameter('signing.nip46.encryption_key', $config['nip46']['encryption_key']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');
    }
}
