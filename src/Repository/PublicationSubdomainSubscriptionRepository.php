<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicationSubdomainSubscription;
use App\Enum\PublicationSubdomainStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PublicationSubdomainSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationSubdomainSubscription::class);
    }

    public function save(PublicationSubdomainSubscription $subscription): void
    {
        $this->getEntityManager()->persist($subscription);
        $this->getEntityManager()->flush();
    }

    public function findByNpub(string $npub): ?PublicationSubdomainSubscription
    {
        return $this->findOneBy(['npub' => $npub]);
    }

    public function findBySubdomain(string $subdomain): ?PublicationSubdomainSubscription
    {
        return $this->findOneBy(['subdomain' => strtolower($subdomain)]);
    }

    public function isSubdomainAvailable(string $subdomain): bool
    {
        // Subdomain is available if no subscription exists, or only cancelled/expired ones exist
        $qb = $this->createQueryBuilder('s')
            ->where('s.subdomain = :subdomain')
            ->andWhere('s.status IN (:blockingStatuses)')
            ->setParameter('subdomain', strtolower($subdomain))
            ->setParameter('blockingStatuses', [
                PublicationSubdomainStatus::PENDING->value,
                PublicationSubdomainStatus::ACTIVE->value,
            ])
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() === null;
    }

    public function findAllWithStatus(?PublicationSubdomainStatus $status = null): array
    {
        if ($status === null) {
            return $this->findBy([], ['createdAt' => 'DESC']);
        }
        return $this->findBy(['status' => $status], ['createdAt' => 'DESC']);
    }

    public function search(string $query): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.npub LIKE :query')
            ->orWhere('s.subdomain LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('s.createdAt', 'DESC');
        return $qb->getQuery()->getResult();
    }
}

