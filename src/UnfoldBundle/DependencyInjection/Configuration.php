<?php

namespace App\UnfoldBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * UnfoldBundle configuration schema
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('unfold');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('themes_path')
                    ->defaultValue('%kernel.project_dir%/src/UnfoldBundle/Resources/themes')
                    ->info('Path to theme directories')
                ->end()
                ->scalarNode('default_theme')
                    ->defaultValue('default')
                    ->info('Default theme to use')
                ->end()
                ->scalarNode('cache_pool')
                    ->defaultValue('unfold.cache')
                    ->info('Cache pool service ID')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

