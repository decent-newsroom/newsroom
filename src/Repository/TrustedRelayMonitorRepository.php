<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrustedRelayMonitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrustedRelayMonitor>
 */
class TrustedRelayMonitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedRelayMonitor::class);
    }

    /**
     * @return string[] All trusted monitor pubkeys (hex).
     */
    public function getTrustedPubkeys(): array
    {
        return array_column(
            $this->createQueryBuilder('t')
                ->select('t.pubkey')
                ->getQuery()
                ->getScalarResult(),
            'pubkey',
        );
    }

    public function isTrusted(string $pubkey): bool
    {
        return $this->find($pubkey) !== null;
    }

    public function add(string $pubkey, ?int $trustedByUserId = null, ?string $note = null): TrustedRelayMonitor
    {
        $existing = $this->find($pubkey);
        if ($existing !== null) {
            if ($note !== null) $existing->setNote($note);
            $this->getEntityManager()->flush();
            return $existing;
        }

        $entity = new TrustedRelayMonitor($pubkey, $trustedByUserId, $note);
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
        return $entity;
    }

    public function remove(string $pubkey): bool
    {
        $entity = $this->find($pubkey);
        if (!$entity) return false;
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
        return true;
    }

    /**
     * Seed from a list of bootstrap pubkeys (strings). Skips already-trusted ones.
     *
     * @param string[] $pubkeys
     */
    public function seedFromConfig(array $pubkeys): void
    {
        foreach ($pubkeys as $pubkey) {
            $pubkey = trim($pubkey);
            if ($pubkey === '' || $this->find($pubkey) !== null) {
                continue;
            }
            $entity = new TrustedRelayMonitor($pubkey, null, 'seeded from config');
            $this->getEntityManager()->persist($entity);
        }
        $this->getEntityManager()->flush();
    }
}

