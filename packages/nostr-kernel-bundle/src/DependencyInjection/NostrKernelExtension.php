<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class NostrKernelExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nostr_kernel.verify_signatures', $config['verify_signatures']);
        $container->setParameter('nostr_kernel.strict_validation', $config['strict_validation']);
        $container->setParameter('nostr_kernel.allow_future_events_seconds', $config['allow_future_events_seconds']);
        $container->setParameter('nostr_kernel.allow_protected_events', $config['allow_protected_events']);
        $container->setParameter('nostr_kernel.use_native_secp256k1_if_available', $config['use_native_secp256k1_if_available']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
    }

    public function getAlias(): string
    {
        return 'nostr_kernel';
    }
}

