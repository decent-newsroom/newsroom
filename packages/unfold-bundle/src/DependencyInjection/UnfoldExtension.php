<?php

namespace DecentNewsroom\UnfoldBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * UnfoldBundle extension for Symfony DI
 */
class UnfoldExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameters from configuration
        $container->setParameter('unfold.themes_path', $config['themes_path']);
        $container->setParameter('unfold.default_theme', $config['default_theme']);
        $container->setParameter('unfold.cache_pool', $config['cache_pool']);
        $container->setParameter('unfold.bundle_path', dirname(__DIR__, 2));

        // Load bundle services
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(dirname(__DIR__, 2) . '/Resources/config')
        );
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'unfold';
    }
}
