<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Dto\UserMetadata;
use App\Enum\FollowPackPurpose;
use App\Repository\FollowPackSourceRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\FollowPackService;
use App\Service\Nostr\NostrKeyService;
use Psr\Log\LoggerInterface;

class FeaturedWritersRecommendations
{
    private const MAX_MEMBERS = 24;
    private const MAX_CANDIDATES = 120;

    public function __construct(
        private readonly FollowPackSourceRepository $sources,
        private readonly FollowPackService $packs,
        private readonly RedisCacheService $cache,
        private readonly ContentAuthorAccessPolicy $authorAccess,
        private readonly NostrKeyService $keys,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $excluded
     * @return array{packNpub: string, packDtag: string, personal: bool, members: list<array{npub: string, metadata: UserMetadata}>}|null
     */
    public function load(int $page, array $excluded, ?string $viewer, ?string $personalCoordinate): ?array
    {
        $source = $this->sources->findByPurpose(FollowPackPurpose::ESSAYIST_WRITERS);
        $coordinate = $source?->getCoordinate() ?? $personalCoordinate;
        if ($coordinate === null || $coordinate === '') {
            return null;
        }

        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || $parts[0] !== '39089'
            || preg_match('/^[0-9a-f]{64}$/i', $parts[1]) !== 1 || $parts[2] === '') {
            $this->logger->warning('Invalid featured writers follow pack coordinate.');
            return null;
        }

        $curator = strtolower($parts[1]);
        $excluded = array_fill_keys(array_map('strtolower', $excluded), true);
        if (isset($excluded[$curator])) {
            return null;
        }
        if ($viewer !== null) {
            $excluded[strtolower($viewer)] = true;
        }

        $members = [];
        $normalizedCoordinate = '39089:' . $curator . ':' . $parts[2];
        foreach ($this->packs->getPubkeysFromCoordinate($normalizedCoordinate) as $pubkey) {
            if (!self::isValidPubkey($pubkey)) {
                $this->logger->warning('Invalid pubkey in featured writers follow pack.');
                continue;
            }
            $pubkey = strtolower($pubkey);
            if (!isset($excluded[$pubkey])) {
                $members[$pubkey] = $pubkey;
            }
        }
        $members = array_values($members);
        if ($members === []) {
            return null;
        }

        $offset = ((max(1, $page) - 1) * self::MAX_MEMBERS) % count($members);
        $candidates = array_slice([...array_slice($members, $offset), ...array_slice($members, 0, $offset)], 0, self::MAX_CANDIDATES);
        $suppressed = array_fill_keys($this->authorAccess->suppressedPubkeys([$curator, ...$candidates]), true);
        if (isset($suppressed[$curator])) {
            return null;
        }
        $candidates = array_slice(array_values(array_filter(
            $candidates,
            static fn (string $pubkey): bool => !isset($suppressed[$pubkey]),
        )), 0, self::MAX_MEMBERS);
        if ($candidates === []) {
            return null;
        }

        $metadata = $this->cache->getMultipleMetadata($candidates);
        $profiles = [];
        foreach ($candidates as $pubkey) {
            $profiles[] = [
                'npub' => $this->keys->convertPublicKeyToBech32($pubkey),
                'metadata' => $metadata[$pubkey] ?? UserMetadata::createDefault($pubkey),
            ];
        }

        return [
            'packNpub' => $this->keys->convertPublicKeyToBech32($curator),
            'packDtag' => $parts[2],
            'personal' => $source === null,
            'members' => $profiles,
        ];
    }

    /**
     * @param list<array{npub: string, metadata: UserMetadata}> $members
     * @return array<int, list<array{npub: string, metadata: UserMetadata}>>
     */
    public function positionBreaks(array $members, int $rowCount): array
    {
        if ($members === []) {
            return [];
        }
        if ($rowCount >= 15 && count($members) > 6) {
            $half = (int) ceil(count($members) / 2);
            return [5 => array_slice($members, 0, $half), 15 => array_slice($members, $half)];
        }

        return [min(5, $rowCount) => $members];
    }

    private static function isValidPubkey(mixed $pubkey): bool
    {
        return is_string($pubkey) && preg_match('/^[0-9a-f]{64}$/i', $pubkey) === 1;
    }
}
