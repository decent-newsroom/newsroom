<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('relay_gateway');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('stream_block_ms')->min(100)->defaultValue(1000)->end()
                ->integerNode('response_ttl_seconds')->min(1)->defaultValue(60)->end()
                ->integerNode('heartbeat_ttl_seconds')->min(5)->defaultValue(30)->end()
                ->integerNode('heartbeat_interval_seconds')->min(1)->defaultValue(5)->end()
                ->integerNode('auth_timeout_seconds')->min(1)->defaultValue(60)->end()
            ->end();

        return $treeBuilder;
    }
}