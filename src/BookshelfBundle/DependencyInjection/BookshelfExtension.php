<?php

namespace DecentNewsroom\BookshelfBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * BookshelfBundle extension for Symfony DI.
 */
class BookshelfExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->setParameter(
            'bookshelf.mercury_api_base_url_default',
            'https://mercury-relay.imwald.eu',
        );

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $container->setParameter('bookshelf.mercury_api_base_url', $config['mercury_api_base_url']);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'bookshelf';
    }
}
