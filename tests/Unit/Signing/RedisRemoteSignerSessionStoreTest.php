<?php

declare(strict_types=1);

namespace App\Tests\Unit\Signing;

use App\Signing\RedisRemoteSignerSessionStore;
use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RedisRemoteSignerSessionStoreTest extends TestCase
{
    public function testItEncryptsAndReadsLegacyCompatibleSessions(): void
    {
        $payload = null;
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('set')
            ->with(
                'nip46_session:' . str_repeat('a', 64),
                $this->callback(static function (string $value) use (&$payload): bool {
                    $payload = $value;

                    return true;
                }),
                ['ex' => 28800],
            )
            ->willReturn(true);
        $redis->method('get')->willReturnCallback(static function () use (&$payload): string {
            return (string) $payload;
        });

        $store = $this->store($redis);
        $subjectId = str_repeat('a', 64);
        $clientPrivkey = str_repeat('1', 64);
        $store->store(
            $subjectId,
            RemoteSignerSession::forBunker(
                $clientPrivkey,
                str_repeat('2', 64),
                ['wss://bunker.example'],
                str_repeat('b', 64),
            ),
        );

        self::assertIsString($payload);
        self::assertStringNotContainsString($clientPrivkey, $payload);

        $legacyPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        unset($legacyPayload['remoteSignerPubkeyHex'], $legacyPayload['relayUrls']);
        $payload = json_encode($legacyPayload, JSON_THROW_ON_ERROR);

        $session = $store->get($subjectId);

        self::assertSame($clientPrivkey, $session?->clientPrivkeyHex());
        self::assertSame(str_repeat('2', 64), $session?->remoteSignerPubkeyHex());
        self::assertSame(['wss://bunker.example'], $session?->relayUrls());
        self::assertSame($subjectId, $session?->userPubkeyHex());
    }

    public function testItChecksRefreshesAndRemovesSessions(): void
    {
        $subjectId = str_repeat('a', 64);
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('exists')
            ->with('nip46_session:' . $subjectId)
            ->willReturn(1);
        $redis->expects($this->once())
            ->method('expire')
            ->with('nip46_session:' . $subjectId, 120)
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('del')
            ->with('nip46_session:' . $subjectId)
            ->willReturn(1);

        $store = $this->store($redis);

        self::assertTrue($store->has($subjectId));
        self::assertTrue($store->refresh($subjectId, 120));
        $store->remove($subjectId);
    }

    private function store(\Redis $redis): RedisRemoteSignerSessionStore
    {
        return new RedisRemoteSignerSessionStore(
            $redis,
            new NullLogger(),
            'test-encryption-key',
            28800,
        );
    }
}
