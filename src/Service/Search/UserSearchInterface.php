<?php

namespace App\Service\Search;

use App\Entity\User;

interface UserSearchInterface
{
    /**
     * Search users by query string (searches across name, displayName, nip05, about)
     * @param string $query The search query
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return User[]
     */
    public function search(string $query, int $limit = 12, int $offset = 0): array;

    /**
     * Find users by npubs
     * @param array $npubs Array of npub identifiers
     * @param int $limit Maximum number of results
     * @return User[]
     */
    public function findByNpubs(array $npubs, int $limit = 200): array;

    /**
     * Find users by role with optional search query
     * @param string $role Role to filter by
     * @param string|null $query Optional search query
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return User[]
     */
    public function findByRole(string $role, ?string $query = null, int $limit = 12, int $offset = 0): array;
}

