<?php

declare(strict_types=1);

namespace App\Tests\Unit\RelayGateway;

use App\RelayGateway\IdentityAuthChallengeSigner;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayUserActivityStore;
use App\Util\RelayUrlNormalizer;
use DecentNewsroom\SigningBundle\Contract\RelayAuthSignerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class IdentityAuthChallengeSignerTest extends TestCase
{
    public function testDuplicateBrowserChallengePublishesMercureOnlyOnce(): void
    {
        $pubkey = str_repeat('a1', 32);
        $relay = 'wss://Relay.Example.com/';
        $challenge = 'same-challenge';
        $fingerprint = $this->fingerprint($pubkey, $relay, $challenge);
        $signedEvent = $this->authEvent($pubkey, $relay, $challenge);
        $signedJson = json_encode($signedEvent, JSON_THROW_ON_ERROR);
        $store = [];
        $published = 0;

        $redis = $this->redisWithStore($store);
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use (&$published, &$store, $fingerprint, $signedJson, $pubkey, $relay, $challenge): bool {
                $published++;
                $this->assertSame('/relay-auth/'.$pubkey, $update->getTopics()[0] ?? null);
                $payload = json_decode($update->getData(), true);
                $this->assertSame($relay, $payload['relay'] ?? null);
                $this->assertSame($challenge, $payload['challenge'] ?? null);
                $this->assertNotEmpty($payload['requestId'] ?? null);

                $store['relay_auth_signed:'.$payload['requestId']] = $signedJson;
                $store['relay_auth_signed_challenge:'.$fingerprint] = $signedJson;

                return true;
            }))
            ->willReturn('mercure-id');

        $remoteSigner = $this->createMock(RelayAuthSignerInterface::class);
        $remoteSigner->expects($this->once())->method('supportsRelayAuth')->willReturn(false);
        $remoteSigner->expects($this->never())->method('signRelayAuth');

        $signer = $this->signer($redis, $hub, $remoteSigner);

        $this->assertNotNull($signer->signAuthChallenge($pubkey, $relay, $challenge, 5));
        $this->assertNotNull($signer->signAuthChallenge($pubkey, $relay, $challenge, 5));
        $this->assertSame(1, $published);
    }

    public function testDuplicateBunkerChallengeAsksRemoteSignerOnlyOnce(): void
    {
        $pubkey = str_repeat('b2', 32);
        $relay = 'wss://Relay.Example.com/';
        $challenge = 'same-bunker-challenge';
        $store = [];
        $redis = $this->redisWithStore($store);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->never())->method('publish');

        $remoteSigner = $this->createMock(RelayAuthSignerInterface::class);
        $remoteSigner->expects($this->once())->method('supportsRelayAuth')->with($pubkey)->willReturn(true);
        $remoteSigner->expects($this->once())
            ->method('signRelayAuth')
            ->with($pubkey, $relay, $challenge, 5)
            ->willReturn($this->authEvent($pubkey, $relay, $challenge));

        $signer = $this->signer($redis, $hub, $remoteSigner);

        $this->assertNotNull($signer->signAuthChallenge($pubkey, $relay, $challenge, 5));
        $this->assertNotNull($signer->signAuthChallenge($pubkey, $relay, $challenge, 5));
    }

    /** @param array<string, string> $store */
    private function redisWithStore(array &$store): \Redis
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturnCallback(
            function (string $key, mixed $value, mixed $options = null) use (&$store): bool {
                if (is_array($options) && in_array('NX', $options, true) && array_key_exists($key, $store)) {
                    return false;
                }

                $store[$key] = (string) $value;

                return true;
            }
        );
        $redis->method('get')->willReturnCallback(
            function (string $key) use (&$store): string|false {
                return $store[$key] ?? false;
            }
        );
        $redis->method('del')->willReturnCallback(
            static function (string $key) use (&$store): int {
                unset($store[$key]);

                return 1;
            }
        );

        return $redis;
    }

    private function signer(\Redis $redis, HubInterface $hub, RelayAuthSignerInterface $remoteSigner): IdentityAuthChallengeSigner
    {
        return new IdentityAuthChallengeSigner(
            $redis,
            $hub,
            $remoteSigner,
            $this->createMock(RelayUserActivityStore::class),
            $this->createMock(RelayHealthStore::class),
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private function authEvent(string $pubkey, string $relay, string $challenge): array
    {
        return [
            'pubkey' => $pubkey,
            'created_at' => time(),
            'kind' => 22242,
            'tags' => [
                ['relay', $relay],
                ['challenge', $challenge],
            ],
            'content' => '',
        ];
    }

    private function fingerprint(string $pubkey, string $relay, string $challenge): string
    {
        return hash('sha256', $pubkey."\0".RelayUrlNormalizer::normalize($relay)."\0".$challenge);
    }
}
