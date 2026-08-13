<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrClientBundle\Tests\Fixtures;

use DecentNewsroom\NostrClientBundle\NostrClientBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class StandaloneTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new NostrClientBundle();
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'nostr-client-test-secret',
            'test' => true,
        ]);

        $container->loadFromExtension('nostr_client', []);
        $container->register(ServiceProbe::class)
            ->setAutowired(true)
            ->setPublic(true);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nostr-client-bundle-cache-' . getmypid();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nostr-client-bundle-log-' . getmypid();
    }
}
