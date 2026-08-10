<?php

namespace DecentNewsroom\BookshelfBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * BookshelfBundle configuration schema.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('bookshelf');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('mercury_api_base_url')
                    ->defaultValue('%env(default:bookshelf.mercury_api_base_url_default:MERCURY_API_BASE_URL)%')
                    ->info('Mercury REST API base URL used by the Bookshelf services.')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
