<?php

namespace App\ChatBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * ChatBundle extension for Symfony DI
 */
class ChatExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('chat.relay_url', $config['relay_url']);
        $container->setParameter('chat.vapid_subject', $config['vapid']['subject']);
        $container->setParameter('chat.vapid_public_key', $config['vapid']['public_key']);
        $container->setParameter('chat.vapid_private_key', $config['vapid']['private_key']);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'chat';
    }
}

