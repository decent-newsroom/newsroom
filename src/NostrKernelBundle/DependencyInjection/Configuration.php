<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('nostr_kernel');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('verify_signatures')->defaultTrue()->end()
                ->booleanNode('strict_validation')->defaultTrue()->end()
                ->integerNode('allow_future_events_seconds')->min(0)->defaultValue(300)->end()
                ->booleanNode('allow_protected_events')->defaultTrue()->end()
                ->booleanNode('use_native_secp256k1_if_available')->defaultTrue()->end()
            ->end();

        return $treeBuilder;
    }
}

