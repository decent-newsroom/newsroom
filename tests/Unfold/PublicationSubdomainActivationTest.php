<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Entity\PublicationSubdomainSubscription;
use App\Entity\UnfoldSite;
use App\Enum\PublicationSubdomainStatus;
use App\Repository\PublicationSubdomainSubscriptionRepository;
use App\Repository\UnfoldSiteRepository;
use App\Service\LNURLResolver;
use App\Service\PublicationSubdomainService;
use App\Unfold\UnfoldSetupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PublicationSubdomainActivationTest extends TestCase
{
    private const COORDINATE = '30040:' . 'a234567890123456789012345678901234567890123456789012345678901234' . ':main';

    public function testRetryingActivationPreservesExistingDatesAndUsesSetup(): void
    {
        $subscription = new PublicationSubdomainSubscription('npub1example', 'Magazine', self::COORDINATE);
        $startedAt = new \DateTimeImmutable('-10 days');
        $expiresAt = new \DateTimeImmutable('+300 days');
        $subscription
            ->setStatus(PublicationSubdomainStatus::ACTIVE)
            ->setStartedAt($startedAt)
            ->setExpiresAt($expiresAt);

        $setup = $this->createMock(UnfoldSetupService::class);
        $setup->expects(self::once())
            ->method('create')
            ->with('magazine', self::COORDINATE)
            ->willReturn((new UnfoldSite())->setSubdomain('magazine')->setCoordinate(self::COORDINATE));

        $entityManager = $this->transactionalEntityManager();
        $entityManager->expects(self::once())->method('persist')->with($subscription);

        $service = $this->service($entityManager, $setup);

        $service->activateSubscription($subscription);

        self::assertSame(PublicationSubdomainStatus::ACTIVE, $subscription->getStatus());
        self::assertSame($startedAt, $subscription->getStartedAt());
        self::assertSame($expiresAt, $subscription->getExpiresAt());
    }

    public function testActivationFailurePropagatesAndDoesNotReportSuccess(): void
    {
        $subscription = new PublicationSubdomainSubscription('npub1example', 'magazine', self::COORDINATE);

        $setup = $this->createMock(UnfoldSetupService::class);
        $setup->expects(self::once())
            ->method('create')
            ->with('magazine', self::COORDINATE)
            ->willThrowException(new \RuntimeException('settings store unavailable'));

        $entityManager = $this->transactionalEntityManager();
        $entityManager->expects(self::once())->method('persist')->with($subscription);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $service = $this->service($entityManager, $setup, $logger);

        $this->expectExceptionObject(new \RuntimeException('settings store unavailable'));
        $service->activateSubscription($subscription);
    }

    private function service(
        EntityManagerInterface $entityManager,
        UnfoldSetupService $setup,
        ?LoggerInterface $logger = null,
    ): PublicationSubdomainService {
        return new PublicationSubdomainService(
            $this->createMock(PublicationSubdomainSubscriptionRepository::class),
            $this->createMock(UnfoldSiteRepository::class),
            $entityManager,
            $logger ?? $this->createMock(LoggerInterface::class),
            $this->createMock(LNURLResolver::class),
            $setup,
        );
    }

    private function transactionalEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush');
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }
}



