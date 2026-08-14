<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Tests\Service\Nostr;

use DecentNewsroom\SigningBundle\Contract\Nip46EventSignerInterface;
use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;
use DecentNewsroom\SigningBundle\Service\Nostr\Nip46SessionStore;
use DecentNewsroom\SigningBundle\Service\Nostr\RelayAuthEventFactory;
use DecentNewsroom\SigningBundle\Service\Nostr\RemoteBunkerSignerStrategy;
use DecentNewsroom\SigningBundle\Storage\RedisRemoteSignerSessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/RedisTestStub.php';

final class RemoteBunkerSignerStrategyTest extends TestCase
{
    public function testItSupportsStoredSessionsDelegatesSigningAndRefreshesTheSession(): void
    {
        $storage = [];
        $expirations = [];
        $store = $this->store($storage, $expirations);
        $ownerId = str_repeat('a', 64);
        $store->store($ownerId, str_repeat('1', 64), str_repeat('2', 64), ['wss://bunker.example']);
        $expirations['nip46_session:'.$ownerId] = 1;

        $signedEvent = ['id' => 'signed-event'];
        $signer = new CapturingEventSigner($signedEvent);
        $strategy = new RemoteBunkerSignerStrategy($store, $signer, new RelayAuthEventFactory(), new NullLogger());
        $unsignedEvent = [
            'kind' => 30023,
            'pubkey' => $ownerId,
            'created_at' => 123,
            'tags' => [['d', 'article-id']],
            'content' => 'draft',
        ];

        self::assertSame('nip46', $strategy->getMethod());
        self::assertTrue($strategy->supports($ownerId));
        self::assertSame($signedEvent, $strategy->sign($ownerId, $unsignedEvent, 7));
        self::assertSame($ownerId, $signer->subjectPubkeyHex);
        self::assertSame($unsignedEvent, $signer->unsignedEvent);
        self::assertSame(str_repeat('2', 64), $signer->session?->remoteSignerPubkeyHex());
        self::assertSame(7, $signer->timeoutSeconds);
        self::assertSame(99, $expirations['nip46_session:'.$ownerId] ?? null);
    }

    public function testItDoesNotRefreshTheSessionWhenSigningFails(): void
    {
        $storage = [];
        $expirations = [];
        $store = $this->store($storage, $expirations);
        $ownerId = str_repeat('a', 64);
        $store->store($ownerId, str_repeat('1', 64), str_repeat('2', 64), ['wss://bunker.example']);
        $expirations['nip46_session:'.$ownerId] = 1;

        $strategy = new RemoteBunkerSignerStrategy($store, new CapturingEventSigner(null), new RelayAuthEventFactory(), new NullLogger());

        self::assertNull($strategy->sign($ownerId, ['kind' => 1, 'content' => 'hello']));
        self::assertSame(1, $expirations['nip46_session:'.$ownerId] ?? null);
    }

    public function testItBuildsRelayAuthEventsThroughTheSameRemoteSignerStrategy(): void
    {
        $storage = [];
        $expirations = [];
        $store = $this->store($storage, $expirations);
        $ownerId = str_repeat('a', 64);
        $store->store($ownerId, str_repeat('1', 64), str_repeat('2', 64), ['wss://bunker.example']);

        $signer = new CapturingEventSigner(['id' => 'signed-auth-event']);
        $strategy = new RemoteBunkerSignerStrategy($store, $signer, new RelayAuthEventFactory(), new NullLogger());

        self::assertSame(['id' => 'signed-auth-event'], $strategy->signRelayAuth($ownerId, 'wss://relay.example', 'challenge-token'));
        self::assertSame(22242, $signer->unsignedEvent['kind'] ?? null);
        self::assertSame('', $signer->unsignedEvent['content'] ?? null);
        self::assertSame([
            ['relay', 'wss://relay.example'],
            ['challenge', 'challenge-token'],
        ], $signer->unsignedEvent['tags'] ?? null);
    }

    /**
     * @param array<string, string> $storage
     * @param array<string, int> $expirations
     */
    private function store(array &$storage, array &$expirations): Nip46SessionStore
    {
        return new Nip46SessionStore(
            new RedisRemoteSignerSessionStore($this->redis($storage, $expirations), new NullLogger(), 'test-encryption-key', 99),
            99,
        );
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

final class CapturingEventSigner implements Nip46EventSignerInterface
{
    public ?string $subjectPubkeyHex = null;

    /** @var array<string, mixed>|null */
    public ?array $unsignedEvent = null;

    public ?RemoteSignerSession $session = null;
    public ?int $timeoutSeconds = null;

    /** @param array<string, mixed>|null $signedEvent */
    public function __construct(private readonly ?array $signedEvent)
    {
    }

    /**
     * @param array<string, mixed> $unsignedEvent
     * @return array<string, mixed>|null
     */
    public function signEvent(
        string $subjectPubkeyHex,
        array $unsignedEvent,
        RemoteSignerSession $session,
        ?int $timeoutSeconds = null,
    ): ?array {
        $this->subjectPubkeyHex = $subjectPubkeyHex;
        $this->unsignedEvent = $unsignedEvent;
        $this->session = $session;
        $this->timeoutSeconds = $timeoutSeconds;

        return $this->signedEvent;
    }
}

