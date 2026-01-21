<?php

declare(strict_types=1);

namespace App\Service;

use App\Credits\Entity\CreditTransaction;
use App\Enum\RolesEnum;
use App\Repository\EventRepository;
use App\Repository\HighlightRepository;
use App\Repository\MagazineRepository;
use App\Repository\UnfoldSiteRepository;
use App\Repository\UserEntityRepository;
use App\Repository\VisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AdminDashboardService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserEntityRepository $userRepository,
        private readonly MagazineRepository $magazineRepository,
        private readonly VisitRepository $visitRepository,
        private readonly EventRepository $eventRepository,
        private readonly HighlightRepository $highlightRepository,
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly RelayAdminService $relayAdminService,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get all dashboard metrics
     */
    public function getDashboardMetrics(): array
    {
        return [
            'content' => $this->getContentStats(),
            'users' => $this->getUserStats(),
            'visits' => $this->getVisitStats(),
            'transactions' => $this->getTransactionStats(),
            'relay' => $this->getRelayStats(),
            'database' => $this->getDatabaseStats(),
        ];
    }

    /**
     * Get content statistics
     */
    public function getContentStats(): array
    {
        try {
            return $this->cache->get('admin_dashboard_content_stats', function (ItemInterface $item) {
                $item->expiresAfter(300); // 5 minutes

                $conn = $this->em->getConnection();

                // Total unique articles (by slug)
                $totalArticlesQuery = "SELECT COUNT(DISTINCT slug) FROM article WHERE slug IS NOT NULL AND slug NOT LIKE '%/%'";
                $totalArticles = (int) $conn->executeQuery($totalArticlesQuery)->fetchOne();

                // Recent articles
                $last24hQuery = "SELECT COUNT(DISTINCT slug) FROM article WHERE created_at >= NOW() - INTERVAL '24 hours' AND slug IS NOT NULL AND slug NOT LIKE '%/%'";
                $last7dQuery = "SELECT COUNT(DISTINCT slug) FROM article WHERE created_at >= NOW() - INTERVAL '7 days' AND slug IS NOT NULL AND slug NOT LIKE '%/%'";
                $last30dQuery = "SELECT COUNT(DISTINCT slug) FROM article WHERE created_at >= NOW() - INTERVAL '30 days' AND slug IS NOT NULL AND slug NOT LIKE '%/%'";

                $articlesLast24h = (int) $conn->executeQuery($last24hQuery)->fetchOne();
                $articlesLast7d = (int) $conn->executeQuery($last7dQuery)->fetchOne();
                $articlesLast30d = (int) $conn->executeQuery($last30dQuery)->fetchOne();

                // Magazine stats
                $totalMagazines = $this->magazineRepository->count([]);
                $publishedMagazines = $this->magazineRepository->count(['publishedAt' => null]);

                return [
                    'total_articles' => $totalArticles,
                    'articles_last_24h' => $articlesLast24h,
                    'articles_last_7d' => $articlesLast7d,
                    'articles_last_30d' => $articlesLast30d,
                    'total_magazines' => $totalMagazines,
                    'published_magazines' => $publishedMagazines,
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get content stats', ['error' => $e->getMessage()]);
            return [
                'total_articles' => 0,
                'articles_last_24h' => 0,
                'articles_last_7d' => 0,
                'articles_last_30d' => 0,
                'total_magazines' => 0,
                'published_magazines' => 0,
                'error' => true,
            ];
        }
    }

    /**
     * Get user statistics
     */
    public function getUserStats(): array
    {
        try {
            return $this->cache->get('admin_dashboard_user_stats', function (ItemInterface $item) {
                $item->expiresAfter(300); // 5 minutes

                $totalUsers = $this->userRepository->count([]);

                $conn = $this->em->getConnection();

                // Count users by role
                $adminQuery = "SELECT COUNT(*) FROM app_user WHERE roles::text LIKE '%ROLE_ADMIN%'";
                $adminCount = (int) $conn->executeQuery($adminQuery)->fetchOne();

                $featuredQuery = "SELECT COUNT(*) FROM app_user WHERE roles::text LIKE '%" . RolesEnum::FEATURED_WRITER->value . "%'";
                $featuredCount = (int) $conn->executeQuery($featuredQuery)->fetchOne();

                $mutedQuery = "SELECT COUNT(*) FROM app_user WHERE roles::text LIKE '%" . RolesEnum::MUTED->value . "%'";
                $mutedCount = (int) $conn->executeQuery($mutedQuery)->fetchOne();

                return [
                    'total_users' => $totalUsers,
                    'admin_users' => $adminCount,
                    'featured_writers' => $featuredCount,
                    'muted_users' => $mutedCount,
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user stats', ['error' => $e->getMessage()]);
            return [
                'total_users' => 0,
                'admin_users' => 0,
                'featured_writers' => 0,
                'muted_users' => 0,
                'error' => true,
            ];
        }
    }

    /**
     * Get visit statistics summary
     */
    public function getVisitStats(): array
    {
        try {
            $visitsLast24h = $this->visitRepository->countVisitsSince(new \DateTimeImmutable('-24 hours'));
            $visitsLast7d = $this->visitRepository->countVisitsSince(new \DateTimeImmutable('-7 days'));
            $totalVisits = $this->visitRepository->getTotalVisits();

            $uniqueVisitorsLast24h = $this->visitRepository->countUniqueSessionsSince(new \DateTimeImmutable('-24 hours'));
            $uniqueVisitorsLast7d = $this->visitRepository->countUniqueSessionsSince(new \DateTimeImmutable('-7 days'));

            $bounceRate = $this->visitRepository->getBounceRate();

            // Top articles in last 24h
            $topArticles = $this->visitRepository->getMostVisitedArticlesSince(new \DateTimeImmutable('-24 hours'), 5);

            return [
                'visits_last_24h' => $visitsLast24h,
                'visits_last_7d' => $visitsLast7d,
                'total_visits' => $totalVisits,
                'unique_visitors_last_24h' => $uniqueVisitorsLast24h,
                'unique_visitors_last_7d' => $uniqueVisitorsLast7d,
                'bounce_rate' => $bounceRate,
                'top_articles_24h' => $topArticles,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get visit stats', ['error' => $e->getMessage()]);
            return [
                'visits_last_24h' => 0,
                'visits_last_7d' => 0,
                'total_visits' => 0,
                'unique_visitors_last_24h' => 0,
                'unique_visitors_last_7d' => 0,
                'bounce_rate' => 0,
                'top_articles_24h' => [],
                'error' => true,
            ];
        }
    }

    /**
     * Get transaction statistics
     */
    public function getTransactionStats(): array
    {
        try {
            return $this->cache->get('admin_dashboard_transaction_stats', function (ItemInterface $item) {
                $item->expiresAfter(300); // 5 minutes

                $transactionRepo = $this->em->getRepository(CreditTransaction::class);
                $totalTransactions = $transactionRepo->count([]);

                $conn = $this->em->getConnection();

                // Recent transactions
                $recentQuery = "SELECT COUNT(*) FROM credit_transaction WHERE created_at >= NOW() - INTERVAL '24 hours'";
                $recentCount = (int) $conn->executeQuery($recentQuery)->fetchOne();

                // Sum of credits and debits
                $creditQuery = "SELECT COALESCE(SUM(amount), 0) FROM credit_transaction WHERE type = 'credit'";
                $debitQuery = "SELECT COALESCE(SUM(amount), 0) FROM credit_transaction WHERE type = 'debit'";

                $totalCredits = (int) $conn->executeQuery($creditQuery)->fetchOne();
                $totalDebits = (int) $conn->executeQuery($debitQuery)->fetchOne();

                // Last transaction
                $lastTransaction = $transactionRepo->findOneBy([], ['createdAt' => 'DESC']);

                return [
                    'total_transactions' => $totalTransactions,
                    'recent_transactions_24h' => $recentCount,
                    'total_credits' => $totalCredits,
                    'total_debits' => $totalDebits,
                    'last_transaction_time' => $lastTransaction?->getCreatedAt(),
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get transaction stats', ['error' => $e->getMessage()]);
            return [
                'total_transactions' => 0,
                'recent_transactions_24h' => 0,
                'total_credits' => 0,
                'total_debits' => 0,
                'last_transaction_time' => null,
                'error' => true,
            ];
        }
    }

    /**
     * Get relay statistics summary
     */
    public function getRelayStats(): array
    {
        try {
            $stats = $this->relayAdminService->getStats();
            $connectivity = $this->relayAdminService->testConnectivity();
            $containerStatus = $this->relayAdminService->getContainerStatus();

            return [
                'accessible' => $stats['relay_accessible'] ?? false,
                'total_events' => $stats['total_events'] ?? 0,
                'connectivity' => $connectivity,
                'container_status' => $containerStatus,
                'error' => isset($stats['error']),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get relay stats', ['error' => $e->getMessage()]);
            return [
                'accessible' => false,
                'total_events' => 0,
                'connectivity' => [],
                'container_status' => [],
                'error' => true,
            ];
        }
    }

    /**
     * Get database statistics
     */
    public function getDatabaseStats(): array
    {
        try {
            return $this->cache->get('admin_dashboard_db_stats', function (ItemInterface $item) {
                $item->expiresAfter(300); // 5 minutes

                $conn = $this->em->getConnection();

                // Count all article rows (including duplicates/versions)
                $totalArticlesQuery = "SELECT COUNT(*) FROM article";
                $totalArticles = (int) $conn->executeQuery($totalArticlesQuery)->fetchOne();

                $totalEvents = $this->eventRepository->count([]);
                $totalHighlights = $this->highlightRepository->count([]);
                $totalUnfoldSites = $this->unfoldSiteRepository->count([]);

                // Count media items from events with kind 20
                $mediaCountQuery = "SELECT COUNT(*) FROM event WHERE kind = 20";
                $totalMedia = (int) $conn->executeQuery($mediaCountQuery)->fetchOne();

                return [
                    'total_articles' => $totalArticles,
                    'total_events' => $totalEvents,
                    'total_media' => $totalMedia,
                    'total_highlights' => $totalHighlights,
                    'total_unfold_sites' => $totalUnfoldSites,
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to get database stats', ['error' => $e->getMessage()]);
            return [
                'total_articles' => 0,
                'total_events' => 0,
                'total_highlights' => 0,
                'total_unfold_sites' => 0,
                'error' => true,
            ];
        }
    }

    /**
     * Clear all dashboard caches
     */
    public function clearCache(): void
    {
        $this->cache->delete('admin_dashboard_content_stats');
        $this->cache->delete('admin_dashboard_user_stats');
        $this->cache->delete('admin_dashboard_transaction_stats');
        $this->cache->delete('admin_dashboard_db_stats');
    }
}
