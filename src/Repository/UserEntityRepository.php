<?php

namespace App\Repository;

use App\Entity\User;
use App\Enum\RolesEnum;
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
        $result = $conn->executeQuery($sql, ['role' => '%' . RolesEnum::FEATURED_WRITER->value . '%']);

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
        $result = $conn->executeQuery($sql, ['role' => '%' . RolesEnum::FEATURED_WRITER->value . '%']);
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

    /**
     * Find all users with the ROLE_MUTED role
     * @return User[]
     * @throws Exception
     */
    public function findMutedUsers(): array
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT id FROM app_user WHERE roles::text LIKE :role';
        $result = $conn->executeQuery($sql, ['role' => '%' . RolesEnum::MUTED->value . '%']);

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
     * Get hex pubkeys of all muted users
     * @return string[]
     * @throws Exception
     */
    public function getMutedPubkeys(): array
    {
        $mutedUsers = $this->findMutedUsers();
        $pubkeys = [];

        foreach ($mutedUsers as $user) {
            $npub = $user->getNpub();
            if (NostrKeyUtil::isNpub($npub)) {
                $pubkeys[] = NostrKeyUtil::npubToHex($npub);
            }
        }

        return $pubkeys;
    }

    /**
     * Search users by query string (searches displayName, name, nip05, about)
     * @param string $query Search query
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return User[]
     */
    public function searchByQuery(string $query, int $limit = 12, int $offset = 0): array
    {
        $searchTerm = '%' . strtolower($query) . '%';

        return $this->createQueryBuilder('u')
            ->where('LOWER(u.displayName) LIKE :query')
            ->orWhere('LOWER(u.name) LIKE :query')
            ->orWhere('LOWER(u.nip05) LIKE :query')
            ->orWhere('LOWER(u.about) LIKE :query')
            ->orWhere('LOWER(u.npub) LIKE :query')
            ->setParameter('query', $searchTerm)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find users by npubs
     * @param array $npubs Array of npub identifiers
     * @param int $limit Maximum number of results
     * @return User[]
     */
    public function findByNpubs(array $npubs, int $limit = 200): array
    {
        if (empty($npubs)) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.npub IN (:npubs)')
            ->setParameter('npubs', $npubs)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find users by role with optional search query
     * @param string $role Role to filter by
     * @param string|null $query Optional search query
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return User[]
     * @throws Exception
     */
    public function findByRoleWithQuery(string $role, ?string $query = null, int $limit = 12, int $offset = 0): array
    {
        $conn = $this->entityManager->getConnection();

        if ($query === null || trim($query) === '') {
            // Just filter by role
            $sql = 'SELECT id FROM app_user WHERE roles::text LIKE :role LIMIT :limit OFFSET :offset';
            $result = $conn->executeQuery($sql, [
                'role' => '%' . $role . '%',
                'limit' => $limit,
                'offset' => $offset
            ]);
        } else {
            // Filter by role and search query
            $searchTerm = '%' . strtolower($query) . '%';
            $sql = 'SELECT id FROM app_user
                    WHERE roles::text LIKE :role
                    AND (LOWER(display_name) LIKE :query
                         OR LOWER(name) LIKE :query
                         OR LOWER(nip05) LIKE :query
                         OR LOWER(about) LIKE :query
                         OR LOWER(npub) LIKE :query)
                    LIMIT :limit OFFSET :offset';
            $result = $conn->executeQuery($sql, [
                'role' => '%' . $role . '%',
                'query' => $searchTerm,
                'limit' => $limit,
                'offset' => $offset
            ]);
        }

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
}
