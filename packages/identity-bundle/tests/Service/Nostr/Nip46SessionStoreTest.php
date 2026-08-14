<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Tests\Service\Nostr;

use DecentNewsroom\IdentityBundle\Service\Nostr\Nip46SessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/RedisTestStub.php';

final class Nip46SessionStoreTest extends TestCase
{
    public function testItStoresRetrievesRefreshesAndRemovesEncryptedSessionData(): void
    {
        $storage = [];
        $expirations = [];
        $redis = $this->redis($storage, $expirations);
        $store = new Nip46SessionStore($redis, new NullLogger(), 'test-encryption-key');

        $store->store(
            'abc123',
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['wss://relay.example'],
        );

        self::assertTrue($store->has('abc123'));
        self::assertSame([
            'clientPrivkeyHex' => str_repeat('1', 64),
            'bunkerPubkeyHex' => str_repeat('2', 64),
            'bunkerRelays' => ['wss://relay.example'],
        ], $store->get('abc123'));
        self::assertSame(Nip46SessionStore::TTL_SECONDS, $expirations['nip46_session:abc123'] ?? null);

        $expirations['nip46_session:abc123'] = 1;
        self::assertTrue($store->refresh('abc123'));
        self::assertSame(Nip46SessionStore::TTL_SECONDS, $expirations['nip46_session:abc123'] ?? null);

        $store->remove('abc123');

        self::assertFalse($store->has('abc123'));
        self::assertNull($store->get('abc123'));
        self::assertFalse($store->refresh('abc123'));
    }

    /**
     * @param array<string,string> $storage
     * @param array<string,int> $expirations
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
