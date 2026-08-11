<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Integration;

use DecentNewsroom\NostrKernelBundle\Tests\Fixtures\ServiceProbe;
use DecentNewsroom\NostrKernelBundle\Tests\Fixtures\StandaloneTestKernel;
use PHPUnit\Framework\TestCase;

final class ContainerCompilationTest extends TestCase
{
    protected function tearDown(): void
    {
        $kernel = $this->kernel ?? null;
        if ($kernel instanceof StandaloneTestKernel) {
            $kernel->shutdown();
        }

        parent::tearDown();
    }

    public function testStandaloneKernelResolvesBundleContractAliases(): void
    {
        $this->kernel = new StandaloneTestKernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');

        self::assertInstanceOf(ServiceProbe::class, $container->get(ServiceProbe::class));
    }

    private ?StandaloneTestKernel $kernel = null;
}
