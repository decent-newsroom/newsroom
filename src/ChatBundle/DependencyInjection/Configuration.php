<?php

namespace App\ChatBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * ChatBundle configuration schema
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('chat');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('relay_url')
                    ->defaultValue('ws://strfry-chat:7778')
                    ->info('WebSocket URL of the private chat relay')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

