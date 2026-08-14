<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Tests\Service\Nostr;

use DecentNewsroom\SigningBundle\Service\Nostr\Nip46SessionStore;
use DecentNewsroom\SigningBundle\Storage\RedisRemoteSignerSessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/RedisTestStub.php';

final class Nip46SessionStoreTest extends TestCase
{
    public function testItStoresRetrievesRefreshesAndRemovesEncryptedRemoteSignerSessionData(): void
    {
        $storage = [];
        $expirations = [];
        $store = new Nip46SessionStore(
            new RedisRemoteSignerSessionStore($this->redis($storage, $expirations), new NullLogger(), 'test-encryption-key', 99),
            99,
        );

        $subjectId = str_repeat('a', 64);
        $store->store(
            $subjectId,
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['wss://bunker.example', 'wss://bunker.example'],
        );

        self::assertTrue($store->has($subjectId));
        self::assertSame([
            'clientPrivkeyHex' => str_repeat('1', 64),
            'bunkerPubkeyHex' => str_repeat('2', 64),
            'bunkerRelays' => ['wss://bunker.example'],
        ], $store->get($subjectId));
        self::assertSame(99, $expirations['nip46_session:'.$subjectId] ?? null);
        self::assertSame($subjectId, $store->getSession($subjectId)?->userPubkeyHex());
        self::assertSame(str_repeat('2', 64), $store->getSession($subjectId)?->remoteSignerPubkeyHex());

        $expirations['nip46_session:'.$subjectId] = 1;
        self::assertTrue($store->refresh($subjectId));
        self::assertSame(99, $expirations['nip46_session:'.$subjectId] ?? null);

        $store->remove($subjectId);

        self::assertFalse($store->has($subjectId));
        self::assertNull($store->get($subjectId));
        self::assertFalse($store->refresh($subjectId));
    }

    /**
     * @param array<string, string> $storage
     * @param array<string, int> $expirations
     */
    private function redis(array &$storage, array &$expirations): \Redis
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturnCallback(static function (string $key, string $value, mixed $options = null) use (&$storage, &$expirations): bool {
            $storage[$key] = $value;
            if (is_array($options) && isset($options['ex'])) {
                $expirations[$key] = (int) $options['ex'];
            }

            return true;
        });
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$storage): string|false {
            return $storage[$key] ?? false;
        });
        $redis->method('exists')->willReturnCallback(static function (string $key) use (&$storage): int {
            return isset($storage[$key]) ? 1 : 0;
        });
        $redis->method('del')->willReturnCallback(static function (string $key) use (&$storage, &$expirations): int {
            $removed = isset($storage[$key]) ? 1 : 0;
            unset($storage[$key], $expirations[$key]);

            return $removed;
        });
        $redis->method('expire')->willReturnCallback(static function (string $key, int $ttlSeconds) use (&$storage, &$expirations): bool {
            if (!isset($storage[$key])) {
                return false;
            }
            $expirations[$key] = $ttlSeconds;

            return true;
        });

        return $redis;
    }
}

