<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('signing');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('app_name')
                    ->defaultValue('Decent Newsroom')
                ->end()
                ->scalarNode('app_url')
                    ->defaultNull()
                ->end()
                ->arrayNode('nostr_connect')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('requested_permissions')
                            ->scalarPrototype()->end()
                            ->defaultValue(['sign_event:27235', 'sign_event:22242', 'get_public_key'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('nip46')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('session_ttl_seconds')
                            ->min(60)
                            ->defaultValue(28800)
                        ->end()
                        ->integerNode('request_timeout_seconds')
                            ->min(1)
                            ->defaultValue(15)
                        ->end()
                        ->scalarNode('redis_prefix')
                            ->defaultValue('nip46_session:')
                        ->end()
                        ->scalarNode('encryption_key')
                            ->defaultValue('%env(APP_ENCRYPTION_KEY)%')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
