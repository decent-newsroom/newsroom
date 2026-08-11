<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Fixtures;

use DecentNewsroom\NostrKernelBundle\NostrKernelBundle;
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
        yield new NostrKernelBundle();
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'nostr-kernel-test-secret',
            'test' => true,
        ]);

        $container->loadFromExtension('nostr_kernel', []);
        $container->register(ServiceProbe::class)
            ->setAutowired(true)
            ->setPublic(true);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nostr-kernel-bundle-cache-' . getmypid();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nostr-kernel-bundle-log-' . getmypid();
    }
}
