<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrClientBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('nostr_client');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('connection_timeout_seconds')->min(1)->defaultValue(10)->end()
                ->booleanNode('auto_reconnect')->defaultTrue()->end()
                ->integerNode('reconnect_initial_delay_ms')->min(1)->defaultValue(500)->end()
                ->integerNode('reconnect_max_delay_ms')->min(1)->defaultValue(60000)->end()
                ->integerNode('reconnect_max_attempts')->min(0)->defaultValue(0)->end()
                ->scalarNode('user_agent')->defaultNull()->end()
            ->end();

        return $treeBuilder;
    }
}
