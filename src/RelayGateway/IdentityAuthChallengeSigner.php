<?php

declare(strict_types=1);

namespace App\RelayGateway;

use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayUserActivityStore;
use DecentNewsroom\IdentityBundle\Service\Nostr\RemoteBunkerSignerStrategy;
use DecentNewsroom\IdentityBundle\Service\NostrSignerStrategyRegistry;
use DecentNewsroom\RelayGatewayBundle\Contract\AuthChallengeSignerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

use function Amp\delay;

final readonly class IdentityAuthChallengeSigner implements AuthChallengeSignerInterface
{
    private const AUTH_PENDING_PREFIX = 'relay_auth_pending:';
    private const AUTH_SIGNED_PREFIX = 'relay_auth_signed:';

    public function __construct(
        private \Redis $redis,
        private HubInterface $hub,
        private NostrSignerStrategyRegistry $signerStrategies,
        private RelayUserActivityStore $activityStore,
        private RelayHealthStore $healthStore,
        private LoggerInterface $logger,
    ) {
    }

    public function signAuthChallenge(string $pubkeyHex, string $relayUrl, string $challenge, int $timeoutSeconds): ?Event
    {
        if (!$this->isHex64($pubkeyHex)) {
            return null;
        }

        $unsignedEvent = [
            'pubkey' => $pubkeyHex,
            'created_at' => time(),
            'kind' => 22242,
            'tags' => [
                ['relay', $relayUrl],
                ['challenge', $challenge],
            ],
            'content' => '',
        ];

        $this->healthStore->setAuthRequired($relayUrl);
        $this->healthStore->setAuthStatus($relayUrl, 'pending');

        $signed = $this->signWithRemoteBunker($pubkeyHex, $relayUrl, $unsignedEvent);
        if ($signed !== null) {
            return $signed;
        }

        return $this->signWithBrowserRoundtrip($pubkeyHex, $relayUrl, $challenge, max(1, $timeoutSeconds));
    }

    /**
     * @param array<string,mixed> $unsignedEvent
     */
    private function signWithRemoteBunker(string $pubkeyHex, string $relayUrl, array $unsignedEvent): ?Event
    {
        try {
            $strategy = $this->signerStrategies->getByMethod(RemoteBunkerSignerStrategy::METHOD);
            if ($strategy === null || !$strategy->supports($pubkeyHex)) {
                return null;
            }

            $signedEvent = $strategy->sign($pubkeyHex, $unsignedEvent);
            if (!is_array($signedEvent)) {
                $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip46', RelayUserActivityStore::STATUS_FAILED, 'signing failed');
                return null;
            }

            $this->healthStore->setAuthStatus($relayUrl, 'user_authed');
            $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip46', RelayUserActivityStore::STATUS_OK);

            return Event::fromArray($signedEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('Relay gateway NIP-46 AUTH signing failed; falling back to browser roundtrip', [
                'relay' => $relayUrl,
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip46', RelayUserActivityStore::STATUS_FAILED, $e->getMessage());

            return null;
        }
    }

    private function signWithBrowserRoundtrip(string $pubkeyHex, string $relayUrl, string $challenge, int $timeoutSeconds): ?Event
    {
        $requestId = Uuid::v4()->toRfc4122();

        try {
            $this->redis->set(
                self::AUTH_PENDING_PREFIX . $requestId,
                json_encode([
                    'relay' => $relayUrl,
                    'challenge' => $challenge,
                    'pubkey' => $pubkeyHex,
                    'created_at' => time(),
                ], JSON_THROW_ON_ERROR),
                ['ex' => $timeoutSeconds],
            );

            $this->hub->publish(new Update(
                '/relay-auth/' . $pubkeyHex,
                json_encode([
                    'requestId' => $requestId,
                    'relay' => $relayUrl,
                    'challenge' => $challenge,
                ], JSON_THROW_ON_ERROR),
            ));

            $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip07', RelayUserActivityStore::STATUS_PENDING);
        } catch (\Throwable $e) {
            $this->logger->error('Relay gateway browser AUTH roundtrip could not be started', [
                'relay' => $relayUrl,
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            $this->healthStore->setAuthStatus($relayUrl, 'failed');
            $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip07', RelayUserActivityStore::STATUS_FAILED, $e->getMessage());

            return null;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            try {
                $signedJson = $this->redis->get(self::AUTH_SIGNED_PREFIX . $requestId);
                if (is_string($signedJson) && $signedJson !== '') {
                    $this->redis->del(self::AUTH_SIGNED_PREFIX . $requestId);
                    $signedEvent = json_decode($signedJson, true);

                    if (is_array($signedEvent)) {
                        $this->healthStore->setAuthStatus($relayUrl, 'user_authed');
                        $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip07', RelayUserActivityStore::STATUS_OK);

                        return Event::fromArray($signedEvent);
                    }

                    break;
                }
            } catch (\RedisException $e) {
                $this->logger->debug('Relay gateway AUTH polling failed', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ]);
            }

            delay(0.05);
        }

        $this->healthStore->setAuthStatus($relayUrl, 'failed');
        $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip07', RelayUserActivityStore::STATUS_FAILED, 'browser did not sign within timeout');

        return null;
    }

    private function isHex64(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}
