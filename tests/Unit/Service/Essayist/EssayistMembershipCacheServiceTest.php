<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Essayist;

use App\Service\Essayist\EssayistMembershipCacheService;
use App\Util\NostrKeyUtil;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EssayistMembershipCacheServiceTest extends TestCase
{
    /**
     * Generate a valid npub and the matching 64-char hex pubkey for the test.
     */
    private function npubPair(): array
    {
        // Deterministic hex pubkey used in tests.
        $hex  = str_repeat('a1', 32);
        $npub = NostrKeyUtil::hexToNpub($hex);
        return [$npub, $hex];
    }

    public function testMarkApprovedWritesPositiveKeyWithTtl(): void
    {
        [$npub, $hex] = $this->npubPair();

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('setex')
            ->with('essayist_member:' . $hex, 600, '1');

        $svc = new EssayistMembershipCacheService($redis, new NullLogger());
        $svc->markApproved($npub);
    }

    public function testMarkRevokedDeletesKeyAndPublishesHexPubkey(): void
    {
        [$npub, $hex] = $this->npubPair();

        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('del')
            ->with('essayist_member:' . $hex);
        $redis->expects($this->once())
            ->method('publish')
            ->with('essayist_member_revoked', $hex);

        $svc = new EssayistMembershipCacheService($redis, new NullLogger());
        $svc->markRevoked($npub);
    }

    public function testInvalidNpubIsIgnoredSilently(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->never())->method('setex');
        $redis->expects($this->never())->method('del');
        $redis->expects($this->never())->method('publish');

        $svc = new EssayistMembershipCacheService($redis, new NullLogger());
        $svc->markApproved('not-an-npub');
        $svc->markRevoked('also-not-an-npub');
    }

    public function testRedisErrorsAreSwallowed(): void
    {
        [$npub, ] = $this->npubPair();

        $redis = $this->createMock(\Redis::class);
        $redis->method('setex')->willThrowException(new \RedisException('connection refused'));
        $redis->method('del')->willThrowException(new \RedisException('connection refused'));

        $svc = new EssayistMembershipCacheService($redis, new NullLogger());

        // Neither call must throw — the role change must still succeed if Redis is down.
        $svc->markApproved($npub);
        $svc->markRevoked($npub);

        $this->addToAssertionCount(1);
    }
}

