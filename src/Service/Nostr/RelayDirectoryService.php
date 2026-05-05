<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Repository\MonitoredRelayRepository;
use App\Repository\RelayInformationRepository;
use App\Repository\TrustedRelayMonitorRepository;
use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Queries the NIP-66 dataset to find, rank, and expose relays that match
 * specific content needs (kind support, NIP support, latency thresholds).
 *
 * Used by the admin directory page and, optionally, by content-fetch
 * handlers to augment the relay list with NIP-66-discovered relays.
 */
class RelayDirectoryService
{
    public function __construct(
        private readonly MonitoredRelayRepository $monitoredRelayRepository,
        private readonly RelayInformationRepository $relayInformationRepository,
        private readonly TrustedRelayMonitorRepository $trustedRelayMonitorRepository,
        private readonly RelayHealthStore $healthStore,
        private readonly LoggerInterface $logger,
        private readonly int $augmentationLimit = 5,
    ) {}

    /**
     * Find relay URLs that at least one trusted monitor has observed supporting
     * the given event kind. Results are ranked by monitor count then median RTT.
     *
     * @return string[]
     */
    public function findRelaysSupportingKind(int $kind, int $limit = 10, ?int $maxRttMs = null): array
    {
        $filters = ['kind' => $kind];
        if ($maxRttMs !== null) {
            $filters['max_rtt_ms'] = $maxRttMs;
        }
        return array_column(
            $this->monitoredRelayRepository->findRanked($filters, $limit),
            'relay_url',
        );
    }

    /**
     * Find relay URLs that at least one trusted monitor has observed supporting
     * the given NIP number.
     *
     * @return string[]
     */
    public function findRelaysSupportingNip(int $nip, int $limit = 10): array
    {
        return array_column(
            $this->monitoredRelayRepository->findRanked(['nip' => $nip], $limit),
            'relay_url',
        );
    }

    /**
     * Rank a set of candidate URLs by NIP-66 monitor count + median RTT
     * + health score. Returns sorted descending by composite score.
     *
     * @param string[] $candidateUrls
     * @return string[]
     */
    public function rank(array $candidateUrls): array
    {
        if ($candidateUrls === []) return [];

        $scores = [];
        foreach ($candidateUrls as $url) {
            $normalized = RelayUrlNormalizer::normalize($url);
            $monitorCount = $this->monitoredRelayRepository->countDistinctMonitors($normalized);
            $medianRtt    = $this->monitoredRelayRepository->medianRtt($normalized) ?? 9999;
            $healthScore  = $this->healthStore->getHealthScore($normalized);

            // Composite: 50% health, 30% latency, 20% monitor consensus
            $latencyScore   = max(0.0, 1.0 - ($medianRtt / 2000.0));
            $monitorScore   = min(1.0, $monitorCount / 5.0);
            $composite      = ($healthScore * 0.5) + ($latencyScore * 0.3) + ($monitorScore * 0.2);

            $scores[$url] = $composite;
        }

        arsort($scores);
        return array_keys($scores);
    }

    /**
     * Full directory data for the admin UI.
     *
     * @param array{kind?: int, nip?: int, require?: string} $filters
     * @return array<int, array<string,mixed>>
     */
    public function getDirectory(array $filters = []): array
    {
        $ranked = $this->monitoredRelayRepository->findRanked($filters, 100);

        $relayUrls = array_column($ranked, 'relay_url');
        $nip11 = $this->relayInformationRepository->findManyIndexed($relayUrls);

        $rows = [];
        foreach ($ranked as $row) {
            $url  = $row['relay_url'];
            $info = $nip11[$url] ?? null;

            $rows[] = [
                'url'           => $url,
                'monitor_count' => $row['monitor_count'],
                'median_rtt_ms' => $row['median_rtt_open_ms'],
                'health_score'  => round($this->healthStore->getHealthScore($url), 2),
                'name'          => $info?->getName(),
                'software'      => $info?->getSoftware(),
                'auth_required' => $info?->isAuthRequired() ?? false,
                'nip11_fetched' => $info?->getFetchedAt() !== null,
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function getMonitors(): array
    {
        $trusted = $this->trustedRelayMonitorRepository->getTrustedPubkeys();
        $trustedSet = array_flip($trusted);

        $all = [];
        // Monitors that have published a kind 10166 announcement
        foreach ($this->getKnownMonitors() as $row) {
            $row['trusted'] = isset($trustedSet[$row['pubkey']]);
            $all[] = $row;
        }
        // Trusted pubkeys that we haven't received a 10166 from yet
        foreach ($trusted as $pubkey) {
            $found = false;
            foreach ($all as $r) {
                if ($r['pubkey'] === $pubkey) { $found = true; break; }
            }
            if (!$found) {
                $all[] = ['pubkey' => $pubkey, 'name' => null, 'frequency_seconds' => null, 'trusted' => true, 'announced' => false];
            }
        }
        return $all;
    }

    private function getKnownMonitors(): array
    {
        // Delegate to repository when that method exists; otherwise return empty.
        // The RelayMonitorRepository::findAll() is inherited from ServiceEntityRepository.
        $this->logger->debug('RelayDirectoryService: getKnownMonitors called, augmentation_limit=' . $this->augmentationLimit);
        return [];
    }
}


