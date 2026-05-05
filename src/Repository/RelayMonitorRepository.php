<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RelayMonitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelayMonitor>
 */
class RelayMonitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelayMonitor::class);
    }

    /**
     * Upsert a kind 10166 announcement. Newer `created_at` wins (idempotent).
     */
    public function upsert(string $pubkey, string $eventId, int $eventCreatedAt, array $fields): RelayMonitor
    {
        $existing = $this->find($pubkey);
        if ($existing !== null) {
            // Reject stale events
            if ($eventCreatedAt <= $existing->getEventCreatedAt()) {
                return $existing;
            }
            $existing->touch($eventId, $eventCreatedAt);
            foreach ($fields as $k => $v) {
                match ($k) {
                    'name'             => $existing->setName($v),
                    'frequency_seconds'=> $existing->setFrequencySeconds($v),
                    'monitored_relays' => $existing->setMonitoredRelays($v),
                    'checks'           => $existing->setChecks($v),
                    default            => null,
                };
            }
            $this->getEntityManager()->flush();
            return $existing;
        }

        $monitor = new RelayMonitor($pubkey, $eventId, $eventCreatedAt);
        foreach ($fields as $k => $v) {
            match ($k) {
                'name'             => $monitor->setName($v),
                'frequency_seconds'=> $monitor->setFrequencySeconds($v),
                'monitored_relays' => $monitor->setMonitoredRelays($v),
                'checks'           => $monitor->setChecks($v),
                default            => null,
            };
        }
        $this->getEntityManager()->persist($monitor);
        $this->getEntityManager()->flush();
        return $monitor;
    }
}

