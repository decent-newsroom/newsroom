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
        $fingerprint = hash('sha256', $pubkey."\0".RelayUrlNormalizer::normalize($relay)."\0".$challenge);
        $sharedSignedKey = 'relay_auth_signed_challenge:'.$fingerprint;
        $signedEvent = [
            'pubkey' => $pubkey,
            'created_at' => time(),
            'kind' => 22242,
            'tags' => [
                ['relay', $relay],
                ['challenge', $challenge],
            ],
            'content' => '',
        ];
        $signedJson = json_encode($signedEvent, JSON_THROW_ON_ERROR);

        $requestId = null;
        $published = 0;

        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturnCallback(
            function (string $key, mixed $value, mixed $options = null) use (&$requestId, $fingerprint): bool {
                if ($key === 'relay_auth_request:'.$fingerprint) {
                    if ($requestId === null) {
                        $requestId = (string) $value;
                        return true;
                    }

                    return false;
                }

                return true;
            }
        );
        $redis->method('get')->willReturnCallback(
            function (string $key) use (&$requestId, $fingerprint, $sharedSignedKey, $signedJson): string|false {
                if ($requestId !== null && $key === 'relay_auth_request:'.$fingerprint) {
                    return $requestId;
                }

                if ($key === $sharedSignedKey) {
                    return $signedJson;
                }

                return false;
            }
        );
        $redis->method('del')->willReturn(1);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use (&$published, $pubkey, $relay, $challenge): bool {
                $published++;
                $this->assertSame('/relay-auth/'.$pubkey, $update->getTopics()[0] ?? null);
                $payload = json_decode($update->getData(), true);
                $this->assertSame($relay, $payload['relay'] ?? null);
                $this->assertSame($challenge, $payload['challenge'] ?? null);
                $this->assertNotEmpty($payload['requestId'] ?? null);

                return true;
            }))
            ->willReturn('mercure-id');

        $remoteSigner = $this->createMock(RelayAuthSignerInterface::class);
        $remoteSigner->method('supportsRelayAuth')->willReturn(false);
        $remoteSigner->method('signRelayAuth')->willReturn(null);

        $activityStore = $this->createMock(RelayUserActivityStore::class);
        $activityStore->expects($this->exactly(3))->method('recordAuth');

        $healthStore = $this->createMock(RelayHealthStore::class);
        $healthStore->expects($this->exactly(2))->method('setAuthRequired');
        $healthStore->expects($this->exactly(4))->method('setAuthStatus');

        $signer = new IdentityAuthChallengeSigner(
            $redis,
            $hub,
            $remoteSigner,
            $activityStore,
            $healthStore,
            new NullLogger(),
        );

        $this->assertNotNull($signer->signAuthChallenge($pubkey, $relay, $challenge, 5));
        $this->assertNotNull($signer->signAuthChallenge($pubkey, $relay, $challenge, 5));
        $this->assertSame(1, $published);
    }
}