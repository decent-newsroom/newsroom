<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Repository\VisitRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AdminDashboardService
{
    private const SNAPSHOT_CACHE_KEY = 'admin_visit_snapshot_v1';

    public function __construct(
        private readonly VisitRepository $visitRepository,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDashboardMetrics(): array
    {
        // Keep the landing page independent of full-table counts and relay probes.
        return ['visits' => $this->getVisitStats()];
    }

    public function getVisitStats(): array
    {
        try {
            return $this->cache->get(self::SNAPSHOT_CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(60);

                return $this->visitRepository->getAdminSnapshot();
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get admin visit snapshot', ['exception' => $e]);

            // An unavailable snapshot must not be presented as zero traffic.
            return ['error' => true];
        }
    }

    public function clearCache(): void
    {
        $this->cache->delete(self::SNAPSHOT_CACHE_KEY);
    }
}
