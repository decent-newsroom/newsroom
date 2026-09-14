<?php

declare(strict_types=1);

namespace AppTests\Unit\Service;

use App\Repository\VisitRepository;
use App\Service\Admin\AdminDashboardService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AdminDashboardServiceTest extends TestCase
{
    public function testDashboardMetricsAndVisitStatsShareCachedSnapshot(): void
    {
        $snapshot = ['total' => 12, 'unique' => 9];
        $visitRepository = $this->createMock(VisitRepository::class);
        $visitRepository->expects(self::once())
            ->method('getAdminSnapshot')
            ->willReturn($snapshot);

        $service = new AdminDashboardService(
            $visitRepository,
            new ArrayAdapter(),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(['visits' => $snapshot], $service->getDashboardMetrics());
        self::assertSame($snapshot, $service->getVisitStats());
    }

    public function testClearCacheCausesRepositoryToBeReadAgain(): void
    {
        $firstSnapshot = ['total' => 12];
        $secondSnapshot = ['total' => 15];
        $visitRepository = $this->createMock(VisitRepository::class);
        $visitRepository->expects(self::exactly(2))
            ->method('getAdminSnapshot')
            ->willReturnOnConsecutiveCalls($firstSnapshot, $secondSnapshot);

        $service = new AdminDashboardService(
            $visitRepository,
            new ArrayAdapter(),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame($firstSnapshot, $service->getVisitStats());
        $service->clearCache();
        self::assertSame($secondSnapshot, $service->getVisitStats());
    }

    public function testRepositoryFailureReturnsErrorAndLogsException(): void
    {
        $exception = new \RuntimeException('database unavailable');
        $visitRepository = $this->createMock(VisitRepository::class);
        $visitRepository->expects(self::once())
            ->method('getAdminSnapshot')
            ->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Failed to get admin visit snapshot',
                self::callback(static function (array $context) use ($exception): bool {
                    return ($context['exception'] ?? null) === $exception;
                }),
            );

        $service = new AdminDashboardService(
            $visitRepository,
            new ArrayAdapter(),
            $logger,
        );

        self::assertSame(['error' => true], $service->getVisitStats());
    }
}
