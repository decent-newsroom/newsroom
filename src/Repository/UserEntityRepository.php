<?php

namespace App\Repository;

use App\Entity\User;
use App\Util\NostrKeyUtil;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class UserEntityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct($registry, User::class);
    }

    public function findOrCreateByUniqueField(User $user): User
    {
        $entity = $this->findOneBy(['npub' => $user->getNpub()]);

        if ($entity !== null) {
            $user->setId($entity->getId());
        } else {
            $this->entityManager->persist($user);
        }

        return $user;
    }

    /**
     * Find all users with the ROLE_FEATURED_WRITER role
     * @return User[]
     * @throws Exception
     */
    public function findFeaturedWriters(): array
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT id FROM app_user WHERE roles::text LIKE :role';
        $result = $conn->executeQuery($sql, ['role' => '%' . User::ROLE_FEATURED_WRITER . '%']);

        $ids = $result->fetchFirstColumn();
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find top featured writers ordered by their most recent article
     * @param int $limit Maximum number of writers to return
     * @return User[]
     * @throws Exception
     */
    public function findTopFeaturedWriters(int $limit = 3): array
    {
        $conn = $this->entityManager->getConnection();

        // Get featured writer IDs and their npubs
        $sql = 'SELECT id, npub FROM app_user WHERE roles::text LIKE :role';
        $result = $conn->executeQuery($sql, ['role' => '%' . User::ROLE_FEATURED_WRITER . '%']);
        $featuredUsers = $result->fetchAllAssociative();

        if (empty($featuredUsers)) {
            return [];
        }

        // Convert npubs to hex pubkeys for article lookup
        $userPubkeys = [];
        foreach ($featuredUsers as $user) {
            $npub = $user['npub'];
            if (NostrKeyUtil::isNpub($npub)) {
                $hex = NostrKeyUtil::npubToHex($npub);
                $userPubkeys[$user['id']] = $hex;
            }
        }

        if (empty($userPubkeys)) {
            return [];
        }

        // Get most recent article date for each featured writer
        $pubkeyPlaceholders = implode(',', array_map(fn($i) => ':pk' . $i, array_keys(array_values($userPubkeys))));
        $sql = "SELECT pubkey, MAX(COALESCE(published_at, created_at)) as latest_article
                FROM article
                WHERE pubkey IN ($pubkeyPlaceholders)
                GROUP BY pubkey";

        $params = [];
        foreach (array_values($userPubkeys) as $i => $pk) {
            $params['pk' . $i] = $pk;
        }

        $articleResult = $conn->executeQuery($sql, $params);
        $latestArticles = [];
        foreach ($articleResult->fetchAllAssociative() as $row) {
            $latestArticles[$row['pubkey']] = $row['latest_article'];
        }

        // Map back to user IDs and sort by latest article
        $userLatest = [];
        foreach ($userPubkeys as $userId => $pubkey) {
            $userLatest[$userId] = $latestArticles[$pubkey] ?? null;
        }

        // Sort by latest article descending (users with articles first, then by date)
        uasort($userLatest, function ($a, $b) {
            if ($a === null && $b === null) return 0;
            if ($a === null) return 1;
            if ($b === null) return -1;
            return strcmp($b, $a); // Descending order
        });

        // Get top N user IDs
        $topUserIds = array_slice(array_keys($userLatest), 0, $limit);

        if (empty($topUserIds)) {
            return [];
        }

        // Fetch users in the sorted order
        $users = $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $topUserIds)
            ->getQuery()
            ->getResult();

        // Re-order users to match the sorted order
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $sortedUsers = [];
        foreach ($topUserIds as $id) {
            if (isset($usersById[$id])) {
                $sortedUsers[] = $usersById[$id];
            }
        }

        return $sortedUsers;
    }
}
