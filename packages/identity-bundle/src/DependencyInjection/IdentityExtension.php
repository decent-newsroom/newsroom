<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class IdentityExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('identity.providers', $config['providers']);
        $container->setParameter('identity.email_otp.code_length', $config['email_otp']['code_length']);
        $container->setParameter('identity.email_otp.code_ttl_seconds', $config['email_otp']['code_ttl_seconds']);
        $container->setParameter('identity.email_otp.max_attempts_per_hour', $config['email_otp']['max_attempts_per_hour']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * Registers the bundle's entity mapping with Doctrine without requiring the
     * host application to hand-configure a mapping path — this is what lets
     * {@see \DecentNewsroom\IdentityBundle\Entity\UserIdentityLink} be persisted
     * through the host's existing EntityManager.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'IdentityBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__ . '/../Entity',
                        'prefix' => 'DecentNewsroom\\IdentityBundle\\Entity',
                        'alias' => 'IdentityBundle',
                    ],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'identity';
    }
}
