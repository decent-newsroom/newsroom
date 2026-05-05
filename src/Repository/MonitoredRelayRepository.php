<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredRelay;
use App\Util\RelayUrlNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoredRelay>
 */
class MonitoredRelayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoredRelay::class);
    }

    /**
     * Upsert a kind 30166 observation. Newer `event_created_at` wins.
     *
     * @param array<string,mixed> $data
     */
    public function upsertObservation(
        string $monitorPubkey,
        string $relayUrl,
        array $data,
        string $eventId,
        int $eventCreatedAt,
    ): MonitoredRelay {
        $normalizedUrl = RelayUrlNormalizer::normalize($relayUrl);

        $existing = $this->findOneBy([
            'monitorPubkey' => $monitorPubkey,
            'relayUrl'      => $normalizedUrl,
        ]);

        if ($existing !== null) {
            if ($eventCreatedAt <= $existing->getEventCreatedAt()) {
                return $existing; // stale, skip
            }
            $existing->updateFrom($eventId, $eventCreatedAt, $data);
            $this->getEntityManager()->flush();
            return $existing;
        }

        $row = new MonitoredRelay($monitorPubkey, $normalizedUrl, $eventId, $eventCreatedAt);
        $row->updateFrom($eventId, $eventCreatedAt, $data);
        $this->getEntityManager()->persist($row);
        $this->getEntityManager()->flush();
        return $row;
    }

    /**
     * Find all monitored observations for a relay URL, across all trusted monitors.
     *
     * @return MonitoredRelay[]
     */
    public function findByRelayUrl(string $relayUrl): array
    {
        return $this->findBy(
            ['relayUrl' => RelayUrlNormalizer::normalize($relayUrl)],
            ['observedAt' => 'DESC'],
        );
    }

    /**
     * Count distinct monitors that have published a 30166 for a given relay.
     */
    public function countDistinctMonitors(string $relayUrl): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.monitorPubkey)')
            ->where('m.relayUrl = :url')
            ->setParameter('url', RelayUrlNormalizer::normalize($relayUrl))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compute the median of an RTT field across all monitors for a relay.
     * Falls back to a simple average when there are too few rows for a proper median.
     */
    public function medianRtt(string $relayUrl, string $field = 'rtt_open_ms'): ?int
    {
        $allowedFields = ['rttOpenMs', 'rttReadMs', 'rttWriteMs'];
        if (!in_array($field, $allowedFields, true)) {
            return null;
        }

        $values = $this->createQueryBuilder('m')
            ->select("m.{$field}")
            ->where('m.relayUrl = :url')
            ->andWhere("m.{$field} IS NOT NULL")
            ->setParameter('url', RelayUrlNormalizer::normalize($relayUrl))
            ->getQuery()
            ->getSingleColumnResult();

        if ($values === []) return null;
        sort($values);
        $mid = (int) floor(count($values) / 2);
        return count($values) % 2 === 1
            ? $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    /**
     * Ranked list of relay URLs, ordered by monitor count desc then median RTT asc.
     * Optionally filtered by accepted kind, supported NIP, or requirements.
     *
     * @param array{kind?: int, nip?: int, require?: string, exclude_require?: string, max_rtt_ms?: int} $filters
     * @return array<int, array{relay_url: string, monitor_count: int, median_rtt_open_ms: int|null}>
     */
    public function findRanked(array $filters = [], int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select(
                'm.relayUrl as relay_url',
                'COUNT(DISTINCT m.monitorPubkey) as monitor_count',
                'AVG(m.rttOpenMs) as avg_rtt_open_ms',
            )
            ->groupBy('m.relayUrl')
            ->orderBy('monitor_count', 'DESC')
            ->addOrderBy('avg_rtt_open_ms', 'ASC')
            ->setMaxResults($limit);

        if (isset($filters['kind'])) {
            // JSON contains — Doctrine doesn't have a portable JSON_CONTAINS
            // so we use a LIKE heuristic, safe for integer arrays without string collisions.
            $qb->andWhere('m.acceptedKinds LIKE :kind_pattern')
               ->setParameter('kind_pattern', '%' . (int) $filters['kind'] . '%');
        }
        if (isset($filters['nip'])) {
            $qb->andWhere('m.supportedNips LIKE :nip_pattern')
               ->setParameter('nip_pattern', '%' . (int) $filters['nip'] . '%');
        }
        if (isset($filters['require'])) {
            $qb->andWhere('m.requirements LIKE :req_pattern')
               ->setParameter('req_pattern', '%' . $filters['require'] . '%');
        }

        /** @var array<int, array<string,mixed>> $raw */
        $raw = $qb->getQuery()->getScalarResult();
        return array_map(static fn(array $r) => [
            'relay_url'           => $r['relay_url'],
            'monitor_count'       => (int) $r['monitor_count'],
            'median_rtt_open_ms'  => $r['avg_rtt_open_ms'] !== null ? (int) round((float)$r['avg_rtt_open_ms']) : null,
        ], $raw);
    }
}

