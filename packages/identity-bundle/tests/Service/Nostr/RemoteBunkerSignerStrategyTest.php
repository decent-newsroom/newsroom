<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Tests\Service\Nostr;

use DecentNewsroom\IdentityBundle\Contract\Nip46AuthEventSignerInterface;
use DecentNewsroom\IdentityBundle\Service\Nostr\Nip46SessionStore;
use DecentNewsroom\IdentityBundle\Service\Nostr\RemoteBunkerSignerStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RemoteBunkerSignerStrategyTest extends TestCase
{
    public function testItSupportsStoredSessionsAndDelegatesAuthSigning(): void
    {
        $storage = [];
        $store = new Nip46SessionStore($this->redis($storage), new NullLogger(), 'test-encryption-key');
        $store->store(
            str_repeat('a', 64),
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['wss://bunker.example'],
        );
        $signer = new CapturingNip46Signer();
        $strategy = new RemoteBunkerSignerStrategy($store, $signer, new NullLogger());

        self::assertSame('nip46', $strategy->getMethod());
        self::assertTrue($strategy->supports(str_repeat('a', 64)));

        $signed = $strategy->sign(str_repeat('a', 64), [
            'kind' => 22242,
            'pubkey' => str_repeat('a', 64),
            'tags' => [
                ['relay', 'wss://relay.example'],
                ['challenge', 'challenge-token'],
            ],
            'content' => '',
        ]);

        self::assertSame(['id' => 'signed-auth-event'], $signed);
        self::assertSame(str_repeat('a', 64), $signer->userPubkeyHex);
        self::assertSame('wss://relay.example', $signer->relayUrl);
        self::assertSame('challenge-token', $signer->challenge);
        self::assertSame([
            'clientPrivkeyHex' => str_repeat('1', 64),
            'bunkerPubkeyHex' => str_repeat('2', 64),
            'bunkerRelays' => ['wss://bunker.example'],
        ], $signer->session);
    }

    public function testItReturnsNullWhenUnsignedEventIsMissingAuthTags(): void
    {
        $storage = [];
        $store = new Nip46SessionStore($this->redis($storage), new NullLogger(), 'test-encryption-key');
        $store->store(str_repeat('a', 64), str_repeat('1', 64), str_repeat('2', 64), ['wss://bunker.example']);

        $strategy = new RemoteBunkerSignerStrategy($store, new CapturingNip46Signer(), new NullLogger());

        self::assertNull($strategy->sign(str_repeat('a', 64), ['tags' => []]));
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

final class CapturingNip46Signer implements Nip46AuthEventSignerInterface
{
    public ?string $userPubkeyHex = null;
    public ?string $relayUrl = null;
    public ?string $challenge = null;

    /** @var array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: string[]}|null */
    public ?array $session = null;

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

        return ['id' => 'signed-auth-event'];
    }
}