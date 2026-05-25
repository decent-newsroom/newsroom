<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Repository\UserRelayListRepository;
use App\Service\Nostr\RelayHealthStore;
use App\Util\NostrKeyUtil;
use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Builds a deduplicated relay pool from current Essayist members' relay lists.
 *
 * The pool is intentionally user-group scoped (Essayist members only) and is
 * meant to back targeted REQ fan-out for member activity/articles without
 * changing global relay defaults.
 */
final class EssayistMemberRelayPoolService
{
    private const REDIS_KEY = 'essayist_member_relay_pool:v1';
    private const TTL = 21600; // 6 hours
    private const MAX_POOL_SIZE = 25;
    private const MAX_MEMBERS_TO_SCAN = 1000;

    public function __construct(
        private readonly \Redis $redis,
        private readonly UserEntityRepository $userRepository,
        private readonly UserRelayListRepository $relayListRepository,
        private readonly RelayHealthStore $healthStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string[]
     */
    public function getRelayUrls(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        $pool = $this->buildPool();
        $this->writeCache($pool);

        return $pool;
    }

    public function invalidate(): void
    {
        try {
            $this->redis->del(self::REDIS_KEY);
        } catch (\RedisException $e) {
            $this->logger->warning('EssayistMemberRelayPoolService: failed to invalidate cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return string[]
     */
    private function buildPool(): array
    {
        $members = $this->userRepository->findByRoleWithQuery(
            RolesEnum::ESSAYIST_MEMBER->value,
            null,
            self::MAX_MEMBERS_TO_SCAN,
        );

        $memberPubkeys = [];
        foreach ($members as $member) {
            $npub = (string) ($member->getNpub() ?? '');
            if ($npub === '' || !NostrKeyUtil::isNpub($npub)) {
                continue;
            }

            try {
                $memberPubkeys[] = NostrKeyUtil::npubToHex($npub);
            } catch (\Throwable) {
                // Skip malformed values quietly; membership row can still be valid.
            }
        }

        $memberPubkeys = array_values(array_unique($memberPubkeys));
        if ($memberPubkeys === []) {
            return [];
        }

        $relayLists = $this->relayListRepository->findByPubkeys($memberPubkeys);

        // Use normalized URL as key to deduplicate across all members.
        $relayMap = [];
        foreach ($relayLists as $relayList) {
            $candidateRelays = $relayList->getWriteRelays();
            if ($candidateRelays === []) {
                $candidateRelays = $relayList->getAllRelays();
            }

            foreach ($candidateRelays as $url) {
                $normalized = RelayUrlNormalizer::normalize((string) $url);
                if (!$this->isUsableRelayUrl($normalized)) {
                    continue;
                }
                $relayMap[$normalized] = $normalized;
            }
        }

        $relayUrls = array_values($relayMap);

        usort($relayUrls, fn (string $a, string $b): int => $this->healthStore->getHealthScore($b) <=> $this->healthStore->getHealthScore($a));

        $pool = array_slice($relayUrls, 0, self::MAX_POOL_SIZE);

        $this->logger->info('EssayistMemberRelayPoolService: rebuilt relay pool', [
            'member_count' => count($memberPubkeys),
            'relay_list_coverage' => count($relayLists),
            'unique_relays_found' => count($relayUrls),
            'pool_size' => count($pool),
        ]);

        return $pool;
    }

    private function isUsableRelayUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (!str_starts_with($url, 'wss://') && !str_starts_with($url, 'ws://')) {
            return false;
        }

        // Avoid local/private relay endpoints in the shared utility pool.
        if (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1') || str_contains($url, 'strfry')) {
            return false;
        }

        return true;
    }

    /**
     * @return string[]|null
     */
    private function readCache(): ?array
    {
        try {
            $raw = $this->redis->get(self::REDIS_KEY);
            if ($raw === false || $raw === null) {
                return null;
            }

            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !isset($decoded['relays']) || !is_array($decoded['relays'])) {
                return null;
            }

            return array_values(array_filter($decoded['relays'], 'is_string'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param string[] $relays
     */
    private function writeCache(array $relays): void
    {
        try {
            $payload = json_encode([
                'relays' => $relays,
                'built_at' => time(),
            ], JSON_THROW_ON_ERROR);

            $this->redis->setex(self::REDIS_KEY, self::TTL, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('EssayistMemberRelayPoolService: failed to write cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

