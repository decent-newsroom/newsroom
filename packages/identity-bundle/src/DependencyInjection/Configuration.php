<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('identity');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('providers')
                    ->info('Names of identity providers enabled on this installation, e.g. nostr, email_otp, passkey, oauth_google.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['nostr'])
                ->end()
                ->arrayNode('email_otp')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('code_length')->min(4)->defaultValue(6)->end()
                        ->integerNode('code_ttl_seconds')->min(30)->defaultValue(600)->end()
                        ->integerNode('max_attempts_per_hour')->min(1)->defaultValue(5)->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
