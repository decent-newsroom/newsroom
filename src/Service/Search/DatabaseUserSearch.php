<?php

namespace App\Service\Search;

use App\Entity\User;
use App\Repository\UserEntityRepository;
use Psr\Log\LoggerInterface;

class DatabaseUserSearch implements UserSearchInterface
{
    public function __construct(
        private readonly UserEntityRepository $userRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function search(string $query, int $limit = 12, int $offset = 0): array
    {
        try {
            return $this->userRepository->searchByQuery($query, $limit, $offset);
        } catch (\Exception $e) {
            $this->logger->error('Database user search error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByNpubs(array $npubs, int $limit = 200): array
    {
        if (empty($npubs)) {
            return [];
        }

        try {
            return $this->userRepository->findByNpubs($npubs, $limit);
        } catch (\Exception $e) {
            $this->logger->error('Database findByNpubs error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByRole(string $role, ?string $query = null, int $limit = 12, int $offset = 0): array
    {
        try {
            return $this->userRepository->findByRoleWithQuery($role, $query, $limit, $offset);
        } catch (\Exception $e) {
            $this->logger->error('Database findByRole error: ' . $e->getMessage());
            return [];
        }
    }
}

