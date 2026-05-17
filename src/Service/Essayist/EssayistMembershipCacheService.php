<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pre-warms the essayist-gateway membership cache in Redis and pushes
 * revocation events to the gateway via Redis pub/sub.
 *
 * The gateway reads `essayist_member:{pubkey_hex}` as its fast path and
 * subscribes to `essayist_member_revoked` to forcibly close live connections.
 *
 * See documentation/essayist-gateway.md.
 */
final class EssayistMembershipCacheService
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'essayist.membership.key_prefix')]
        private readonly string $keyPrefix = 'essayist_member:',
        #[Autowire(param: 'essayist.membership.positive_ttl_seconds')]
        private readonly int $positiveTtlSeconds = 600,
        #[Autowire(param: 'essayist.membership.revocation_channel')]
        private readonly string $revocationChannel = 'essayist_member_revoked',
    ) {
    }

    /**
     * Mark a member's pubkey as approved in the gateway's fast-path cache.
     * Call after granting `ROLE_ESSAYIST_MEMBER`.
     */
    public function markApproved(string $npub): void
    {
        $hex = $this->npubToHex($npub);
        if ($hex === null) {
            return;
        }

        try {
            $this->redis->setex($this->keyPrefix . $hex, $this->positiveTtlSeconds, '1');
        } catch (\Throwable $e) {
            // Redis being down must not block the grant action — log and continue.
            $this->logger->warning('Failed to write essayist membership cache', [
                'pubkey'  => substr($hex, 0, 8),
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Drop the cached decision and publish a revocation so the gateway closes
     * any live authenticated WebSocket connections owned by this pubkey.
     * Call after revoking `ROLE_ESSAYIST_MEMBER`.
     */
    public function markRevoked(string $npub): void
    {
        $hex = $this->npubToHex($npub);
        if ($hex === null) {
            return;
        }

        try {
            $this->redis->del($this->keyPrefix . $hex);
            $this->redis->publish($this->revocationChannel, $hex);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish essayist membership revocation', [
                'pubkey' => substr($hex, 0, 8),
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function npubToHex(string $npub): ?string
    {
        if (!NostrKeyUtil::isNpub($npub)) {
            return null;
        }
        try {
            return NostrKeyUtil::npubToHex($npub);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}

