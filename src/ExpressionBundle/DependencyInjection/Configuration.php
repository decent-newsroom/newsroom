<?php

namespace App\ExpressionBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('expression');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('cache_ttl')
                    ->defaultValue(300)
                    ->info('TTL in seconds for cached feed results')
                ->end()
                ->integerNode('max_depth')
                    ->defaultValue(5)
                    ->info('Maximum recursion depth for nested expression references')
                ->end()
                ->integerNode('max_execution_time')
                    ->defaultValue(30)
                    ->info('Maximum execution time in seconds before timeout')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

