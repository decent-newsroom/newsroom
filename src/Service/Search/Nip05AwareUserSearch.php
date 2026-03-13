<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\User;
use App\Service\Nostr\Nip05VerificationService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;

/**
 * Decorator that adds NIP-05 lookup capability to user search.
 *
 * When the search query looks like a NIP-05 identifier (contains "@" with a
 * valid domain), the service resolves it via the NIP-05 well-known endpoint
 * to obtain the hex pubkey, then looks up the user by npub.
 *
 * Results from NIP-05 resolution are merged with normal search results so
 * the resolved user always appears first and is never duplicated.
 */
class Nip05AwareUserSearch implements UserSearchInterface
{
    public function __construct(
        private readonly UserSearchInterface $inner,
        private readonly Nip05VerificationService $nip05Service,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function search(string $query, int $limit = 12, int $offset = 0): array
    {
        $nip05User = null;

        // Only attempt NIP-05 resolution on the first page and when the query
        // looks like an internet identifier (local@domain.tld).
        if ($offset === 0 && $this->looksLikeNip05($query)) {
            $nip05User = $this->resolveNip05User($query);
        }

        // Run the normal search
        $results = $this->inner->search($query, $limit, $offset);

        if ($nip05User !== null) {
            $results = $this->mergeNip05Result($nip05User, $results);
        }

        return $results;
    }

    public function findByNpubs(array $npubs, int $limit = 200): array
    {
        return $this->inner->findByNpubs($npubs, $limit);
    }

    public function findByRole(string $role, ?string $query = null, int $limit = 12, int $offset = 0): array
    {
        return $this->inner->findByRole($role, $query, $limit, $offset);
    }

    /**
     * Check whether a query string looks like a NIP-05 identifier.
     * Must contain exactly one "@", the local part must be [a-z0-9-_.]+,
     * and the domain must contain at least one dot.
     */
    private function looksLikeNip05(string $query): bool
    {
        $query = trim($query);

        if (substr_count($query, '@') !== 1) {
            return false;
        }

        [$local, $domain] = explode('@', $query, 2);

        if (!preg_match('/^[a-zA-Z0-9\-_.]+$/', $local)) {
            return false;
        }

        if (empty($domain) || !str_contains($domain, '.')) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a NIP-05 identifier to a User object.
     *
     * When NIP-05 resolves to a hex pubkey, we first check the DB/ES for a
     * full profile. If not found we build a transient (non-persisted) User
     * from the NIP-05 data so the caller always gets a result:
     *   - npub  = hex pubkey converted to bech32
     *   - name / displayName = the local-part of the identifier (e.g. "bob")
     *   - nip05 = the full identifier (e.g. "bob@example.com")
     */
    private function resolveNip05User(string $nip05): ?User
    {
        try {
            $nip05 = trim($nip05);
            $result = $this->nip05Service->resolve($nip05);

            if ($result['pubkey'] === null) {
                $this->logger->debug('NIP-05 user search: resolve returned no pubkey', ['nip05' => $nip05]);
                return null;
            }

            $npub = NostrKeyUtil::hexToNpub($result['pubkey']);

            // Try to find a full profile in DB/ES first
            $users = $this->inner->findByNpubs([$npub], 1);

            if (!empty($users)) {
                $this->logger->info('NIP-05 user search: found user via NIP-05 lookup', [
                    'nip05' => $nip05,
                    'npub' => $npub,
                ]);
                return $users[0];
            }

            // Build a transient User from the NIP-05 data so the result
            // is never lost even when the profile is not indexed locally.
            [$localPart] = explode('@', $nip05, 2);

            $user = new User();
            $user->setNpub($npub);
            $user->setName($localPart);
            $user->setDisplayName($localPart);
            $user->setNip05($nip05);

            $this->logger->info('NIP-05 user search: built transient user from NIP-05', [
                'nip05' => $nip05,
                'npub' => $npub,
            ]);

            return $user;
        } catch (\Throwable $e) {
            $this->logger->warning('NIP-05 user search: resolution failed', [
                'nip05' => $nip05,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Merge the NIP-05 resolved user into search results, deduplicating
     * and ensuring the resolved user appears first.
     *
     * @param User   $nip05User The user found via NIP-05 lookup
     * @param User[] $results   The normal search results
     * @return User[]
     */
    private function mergeNip05Result(User $nip05User, array $results): array
    {
        // Deduplicate by npub (transient users have no id)
        $resolvedNpub = $nip05User->getNpub();
        $filtered = array_filter($results, fn(User $u) => $u->getNpub() !== $resolvedNpub);

        // Prepend the NIP-05 resolved user so they always appear first
        return array_values(array_merge([$nip05User], $filtered));
    }
}

