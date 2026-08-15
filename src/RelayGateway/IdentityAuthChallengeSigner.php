<?php

declare(strict_types=1);

namespace App\RelayGateway;

use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayUserActivityStore;
use App\Util\RelayUrlNormalizer;
use DecentNewsroom\RelayGatewayBundle\Contract\AuthChallengeSignerInterface;
use DecentNewsroom\SigningBundle\Contract\RelayAuthSignerInterface;
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
    private const AUTH_REQUEST_PREFIX = 'relay_auth_request:';
    private const AUTH_SIGNED_CHALLENGE_PREFIX = 'relay_auth_signed_challenge:';
    private const AUTH_SIGNER_PREFIX = 'relay_auth_signer:';

    public function __construct(
        private \Redis $redis,
        private HubInterface $hub,
        private RelayAuthSignerInterface $relayAuthSigner,
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

        $timeoutSeconds = max(1, $timeoutSeconds);
        $fingerprint = $this->challengeFingerprint($pubkeyHex, $relayUrl, $challenge);

        $this->healthStore->setAuthRequired($relayUrl);
        $this->healthStore->setAuthStatus($relayUrl, 'pending');

        $signed = $this->readSignedChallenge($fingerprint, $relayUrl);
        if ($signed !== null) {
            return $signed;
        }

        $signed = $this->signWithRemoteBunker($pubkeyHex, $relayUrl, $challenge, $timeoutSeconds, $fingerprint);
        if ($signed !== null) {
            return $signed;
        }

        return $this->signWithBrowserRoundtrip($pubkeyHex, $relayUrl, $challenge, $timeoutSeconds, $fingerprint);
    }

    private function signWithRemoteBunker(string $pubkeyHex, string $relayUrl, string $challenge, int $timeoutSeconds, string $fingerprint): ?Event
    {
        try {
            $hadRemoteSession = $this->relayAuthSigner->supportsRelayAuth($pubkeyHex);
            if (!$hadRemoteSession) {
                return null;
            }

            if (!$this->acquireRemoteSigningSlot($fingerprint, $timeoutSeconds)) {
                return $this->waitForSignedChallenge($fingerprint, $relayUrl, $timeoutSeconds);
            }

            $signedEvent = $this->relayAuthSigner->signRelayAuth($pubkeyHex, $relayUrl, $challenge, $timeoutSeconds);
            if (!is_array($signedEvent)) {
                $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip46', RelayUserActivityStore::STATUS_FAILED, 'signing failed');

                return null;
            }

            $this->storeSignedChallenge($fingerprint, $signedEvent, $timeoutSeconds);
            $this->healthStore->setAuthStatus($relayUrl, 'user_authed');
            $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip46', RelayUserActivityStore::STATUS_OK);

            return Event::fromArray($signedEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('Relay gateway NIP-46 AUTH signing failed; falling back to browser roundtrip', [
                'relay' => $relayUrl,
                'pubkey' => substr($pubkeyHex, 0, 8).'...',
                'error' => $e->getMessage(),
            ]);
            $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip46', RelayUserActivityStore::STATUS_FAILED, $e->getMessage());

            return null;
        }
    }

    private function signWithBrowserRoundtrip(string $pubkeyHex, string $relayUrl, string $challenge, int $timeoutSeconds, string $fingerprint): ?Event
    {
        $requestId = Uuid::v4()->toRfc4122();
        $requestKey = self::AUTH_REQUEST_PREFIX.$fingerprint;

        try {
            $ownsChallenge = $this->redis->set($requestKey, $requestId, ['NX', 'EX' => $timeoutSeconds]) !== false;

            if (!$ownsChallenge) {
                $existingRequestId = $this->redis->get($requestKey);
                if (is_string($existingRequestId) && $existingRequestId !== '') {
                    $requestId = $existingRequestId;
                } else {
                    $ownsChallenge = $this->redis->set($requestKey, $requestId, ['NX', 'EX' => $timeoutSeconds]) !== false;
                }
            }

            if ($ownsChallenge) {
                $this->redis->set(
                    self::AUTH_PENDING_PREFIX.$requestId,
                    json_encode([
                        'relay' => $relayUrl,
                        'challenge' => $challenge,
                        'pubkey' => $pubkeyHex,
                        'fingerprint' => $fingerprint,
                        'created_at' => time(),
                    ], JSON_THROW_ON_ERROR),
                    ['ex' => $timeoutSeconds],
                );

                $this->hub->publish(new Update(
                    '/relay-auth/'.$pubkeyHex,
                    json_encode([
                        'requestId' => $requestId,
                        'relay' => $relayUrl,
                        'challenge' => $challenge,
                    ], JSON_THROW_ON_ERROR),
                ));

                $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip07', RelayUserActivityStore::STATUS_PENDING);
            } else {
                $this->logger->debug('Relay gateway browser AUTH challenge already pending; waiting for existing signature', [
                    'relay' => $relayUrl,
                    'pubkey' => substr($pubkeyHex, 0, 8).'...',
                    'request_id' => $requestId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Relay gateway browser AUTH roundtrip could not be started', [
                'relay' => $relayUrl,
                'pubkey' => substr($pubkeyHex, 0, 8).'...',
                'error' => $e->getMessage(),
            ]);
            $this->healthStore->setAuthStatus($relayUrl, 'failed');
            $this->activityStore->recordAuth($pubkeyHex, $relayUrl, 'nip07', RelayUserActivityStore::STATUS_FAILED, $e->getMessage());

            return null;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            try {
                $signedJson = $this->redis->get(self::AUTH_SIGNED_PREFIX.$requestId);
                if (!is_string($signedJson) || $signedJson === '') {
                    $signedJson = $this->redis->get(self::AUTH_SIGNED_CHALLENGE_PREFIX.$fingerprint);
                }

                if (is_string($signedJson) && $signedJson !== '') {
                    $this->redis->del(self::AUTH_SIGNED_PREFIX.$requestId);
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

    private function acquireRemoteSigningSlot(string $fingerprint, int $timeoutSeconds): bool
    {
        try {
            return $this->redis->set(self::AUTH_SIGNER_PREFIX.$fingerprint, '1', ['NX', 'EX' => $timeoutSeconds]) !== false;
        } catch (\RedisException $e) {
            $this->logger->debug('Relay gateway remote AUTH signing dedupe unavailable', [
                'fingerprint' => substr($fingerprint, 0, 12).'...',
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    private function waitForSignedChallenge(string $fingerprint, string $relayUrl, int $timeoutSeconds): ?Event
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $signed = $this->readSignedChallenge($fingerprint, $relayUrl);
            if ($signed !== null) {
                return $signed;
            }

            delay(0.05);
        }

        return null;
    }

    private function readSignedChallenge(string $fingerprint, string $relayUrl): ?Event
    {
        try {
            $signedJson = $this->redis->get(self::AUTH_SIGNED_CHALLENGE_PREFIX.$fingerprint);
            if (!is_string($signedJson) || $signedJson === '') {
                return null;
            }

            $signedEvent = json_decode($signedJson, true);
            if (!is_array($signedEvent)) {
                return null;
            }

            $this->healthStore->setAuthStatus($relayUrl, 'user_authed');

            return Event::fromArray($signedEvent);
        } catch (\RedisException $e) {
            $this->logger->debug('Relay gateway shared AUTH read failed', [
                'fingerprint' => substr($fingerprint, 0, 12).'...',
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Relay gateway shared AUTH event could not be decoded', [
                'fingerprint' => substr($fingerprint, 0, 12).'...',
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /** @param array<string, mixed> $signedEvent */
    private function storeSignedChallenge(string $fingerprint, array $signedEvent, int $timeoutSeconds): void
    {
        try {
            $this->redis->set(
                self::AUTH_SIGNED_CHALLENGE_PREFIX.$fingerprint,
                json_encode($signedEvent, JSON_THROW_ON_ERROR),
                ['ex' => max(60, $timeoutSeconds)],
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Relay gateway shared AUTH write failed', [
                'fingerprint' => substr($fingerprint, 0, 12).'...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function challengeFingerprint(string $pubkeyHex, string $relayUrl, string $challenge): string
    {
        return hash('sha256', $pubkeyHex."\0".RelayUrlNormalizer::normalize($relayUrl)."\0".$challenge);
    }

    private function isHex64(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}

