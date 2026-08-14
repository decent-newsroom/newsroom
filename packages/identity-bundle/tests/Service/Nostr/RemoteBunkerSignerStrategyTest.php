<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Tests\Service\Nostr;

use DecentNewsroom\IdentityBundle\Contract\Nip46AuthEventSignerInterface;
use DecentNewsroom\IdentityBundle\Service\Nostr\Nip46SessionStore;
use DecentNewsroom\IdentityBundle\Service\Nostr\RemoteBunkerSignerStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/RedisTestStub.php';

final class RemoteBunkerSignerStrategyTest extends TestCase
{
    public function testItSupportsStoredSessionsDelegatesAuthSigningAndRefreshesTheSession(): void
    {
        $storage = [];
        $expirations = [];
        $store = new Nip46SessionStore($this->redis($storage, $expirations), new NullLogger(), 'test-encryption-key');
        $ownerId = str_repeat('a', 64);
        $store->store(
            $ownerId,
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['wss://bunker.example'],
        );
        $expirations['nip46_session:' . $ownerId] = 1;

        $signer = new CapturingNip46Signer();
        $strategy = new RemoteBunkerSignerStrategy($store, $signer, new NullLogger());

        self::assertSame('nip46', $strategy->getMethod());
        self::assertTrue($strategy->supports($ownerId));

        $signed = $strategy->sign($ownerId, [
            'kind' => 22242,
            'pubkey' => $ownerId,
            'tags' => [
                ['relay', 'wss://relay.example'],
                ['challenge', 'challenge-token'],
            ],
            'content' => '',
        ]);

        self::assertSame(['id' => 'signed-auth-event'], $signed);
        self::assertSame($ownerId, $signer->userPubkeyHex);
        self::assertSame('wss://relay.example', $signer->relayUrl);
        self::assertSame('challenge-token', $signer->challenge);
        self::assertSame([
            'clientPrivkeyHex' => str_repeat('1', 64),
            'bunkerPubkeyHex' => str_repeat('2', 64),
            'bunkerRelays' => ['wss://bunker.example'],
        ], $signer->session);
        self::assertSame(Nip46SessionStore::TTL_SECONDS, $expirations['nip46_session:' . $ownerId] ?? null);
    }

    public function testItDoesNotRefreshTheSessionWhenSigningFails(): void
    {
        $storage = [];
        $expirations = [];
        $store = new Nip46SessionStore($this->redis($storage, $expirations), new NullLogger(), 'test-encryption-key');
        $ownerId = str_repeat('a', 64);
        $store->store($ownerId, str_repeat('1', 64), str_repeat('2', 64), ['wss://bunker.example']);
        $expirations['nip46_session:' . $ownerId] = 1;

        $signer = new CapturingNip46Signer(null);
        $strategy = new RemoteBunkerSignerStrategy($store, $signer, new NullLogger());

        self::assertNull($strategy->sign($ownerId, [
            'kind' => 22242,
            'pubkey' => $ownerId,
            'tags' => [
                ['relay', 'wss://relay.example'],
                ['challenge', 'challenge-token'],
            ],
            'content' => '',
        ]));
        self::assertSame(1, $expirations['nip46_session:' . $ownerId] ?? null);
    }

    public function testItReturnsNullWhenUnsignedEventIsMissingAuthTags(): void
    {
        $storage = [];
        $expirations = [];
        $store = new Nip46SessionStore($this->redis($storage, $expirations), new NullLogger(), 'test-encryption-key');
        $store->store(str_repeat('a', 64), str_repeat('1', 64), str_repeat('2', 64), ['wss://bunker.example']);

        $strategy = new RemoteBunkerSignerStrategy($store, new CapturingNip46Signer(), new NullLogger());

        self::assertNull($strategy->sign(str_repeat('a', 64), ['tags' => []]));
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

final class CapturingNip46Signer implements Nip46AuthEventSignerInterface
{
    public ?string $userPubkeyHex = null;
    public ?string $relayUrl = null;
    public ?string $challenge = null;

    /** @var array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: string[]}|null */
    public ?array $session = null;

    /** @param array<string,mixed>|null $signedEvent */
    public function __construct(private readonly ?array $signedEvent = ['id' => 'signed-auth-event'])
    {
    }

    public function signAuthEvent(
        string $userPubkeyHex,
        string $relayUrl,
        string $challenge,
        array $session,
        int $timeoutSeconds = 15,
    ): ?array {
        $this->userPubkeyHex = $userPubkeyHex;
        $this->relayUrl = $relayUrl;
        $this->challenge = $challenge;
        $this->session = $session;

        return $this->signedEvent;
    }
}
