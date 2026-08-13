<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Tests\Service\Nostr;

use DecentNewsroom\IdentityBundle\Contract\Nip46AuthEventSignerInterface;
use DecentNewsroom\IdentityBundle\Service\Nostr\Nip46SessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Nip46SessionStoreTest extends TestCase
{
    public function testItStoresRetrievesAndRemovesEncryptedSessionData(): void
    {
        $storage = [];
        $redis = $this->redis($storage);
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

        $store->remove('abc123');

        self::assertFalse($store->has('abc123'));
        self::assertNull($store->get('abc123'));
    }

    /**
     * @param array<string,string> $storage
     */
    private function redis(array &$storage): \Redis
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturnCallback(static function (string $key, string $value) use (&$storage): bool {
            $storage[$key] = $value;

            return true;
        });
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$storage): string|false {
            return $storage[$key] ?? false;
        });
        $redis->method('exists')->willReturnCallback(static function (string $key) use (&$storage): int {
            return isset($storage[$key]) ? 1 : 0;
        });
        $redis->method('del')->willReturnCallback(static function (string $key) use (&$storage): int {
            $removed = isset($storage[$key]) ? 1 : 0;
            unset($storage[$key]);

            return $removed;
        });

        return $redis;
    }
}