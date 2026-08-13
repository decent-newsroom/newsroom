<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrClientBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class NostrClientExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nostr_client.connection_timeout_seconds', $config['connection_timeout_seconds']);
        $container->setParameter('nostr_client.auto_reconnect', $config['auto_reconnect']);
        $container->setParameter('nostr_client.reconnect_initial_delay_ms', $config['reconnect_initial_delay_ms']);
        $container->setParameter('nostr_client.reconnect_max_delay_ms', $config['reconnect_max_delay_ms']);
        $container->setParameter('nostr_client.reconnect_max_attempts', $config['reconnect_max_attempts']);
        $container->setParameter('nostr_client.user_agent', $config['user_agent']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
    }

    public function getAlias(): string
    {
        return 'nostr_client';
    }
}
