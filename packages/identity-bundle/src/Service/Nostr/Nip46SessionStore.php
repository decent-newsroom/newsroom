<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Service\Nostr;

use Psr\Log\LoggerInterface;

/**
 * Encrypted Redis store for ephemeral NIP-46 remote-signer sessions.
 *
 * The client private key is the per-session NIP-46 RPC key used for encryption
 * and request-event signing. It is not the user's nsec, but it is still stored
 * encrypted at rest because it can ask the remote signer to sign approved event
 * kinds while the session is alive.
 */
final class Nip46SessionStore
{
    private const REDIS_PREFIX = 'nip46_session:';
    public const TTL_SECONDS = 28800; // 8 hours, matching Mercure cookie lifetime.

    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $aesKey;

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        string $encryptionKey,
    ) {
        $this->aesKey = hash('sha256', $encryptionKey, true);
    }

    /**
     * @param string[] $bunkerRelays
     */
    public function store(
        string $subjectId,
        string $clientPrivkeyHex,
        string $bunkerPubkeyHex,
        array $bunkerRelays,
    ): void {
        $data = json_encode([
            'clientPrivkeyEnc' => $this->encrypt($clientPrivkeyHex),
            'bunkerPubkey' => $bunkerPubkeyHex,
            'bunkerRelays' => array_values($bunkerRelays),
            'storedAt' => time(),
        ]);

        if ($data === false) {
            throw new \RuntimeException('NIP-46 session data could not be encoded.');
        }

        try {
            $this->redis->set(
                self::REDIS_PREFIX . $subjectId,
                $data,
                ['ex' => self::TTL_SECONDS],
            );
            $this->logger->debug('Nip46SessionStore: stored session', [
                'subject' => substr($subjectId, 0, 8) . '...',
                'bunker_pubkey' => substr($bunkerPubkeyHex, 0, 8) . '...',
                'relay_count' => count($bunkerRelays),
            ]);
        } catch (\RedisException $e) {
            $this->logger->error('Nip46SessionStore: failed to store session', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function has(string $subjectId): bool
    {
        try {
            return (int) $this->redis->exists(self::REDIS_PREFIX . $subjectId) > 0;
        } catch (\RedisException $e) {
            $this->logger->warning('Nip46SessionStore: Redis error on exists', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @return array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: string[]}|null
     */
    public function get(string $subjectId): ?array
    {
        try {
            $json = $this->redis->get(self::REDIS_PREFIX . $subjectId);
        } catch (\RedisException $e) {
            $this->logger->warning('Nip46SessionStore: Redis error on get', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!is_string($json) || $json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        try {
            $clientPrivkeyHex = $this->decrypt((string) ($data['clientPrivkeyEnc'] ?? ''));
            $bunkerPubkeyHex = (string) ($data['bunkerPubkey'] ?? '');
            $bunkerRelays = $data['bunkerRelays'] ?? [];

            if (!is_array($bunkerRelays)) {
                return null;
            }

            return [
                'clientPrivkeyHex' => $clientPrivkeyHex,
                'bunkerPubkeyHex' => $bunkerPubkeyHex,
                'bunkerRelays' => array_values(array_filter($bunkerRelays, 'is_string')),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Nip46SessionStore: failed to decrypt session', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function refresh(string $subjectId, int $ttlSeconds = self::TTL_SECONDS): bool
    {
        $key = self::REDIS_PREFIX . $subjectId;

        try {
            if ((int) $this->redis->exists($key) <= 0) {
                return false;
            }

            return $this->redis->expire($key, max(1, $ttlSeconds));
        } catch (\RedisException $e) {
            $this->logger->warning('Nip46SessionStore: failed to refresh session TTL', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function remove(string $subjectId): void
    {
        try {
            $this->redis->del(self::REDIS_PREFIX . $subjectId);
        } catch (\RedisException $e) {
            $this->logger->warning('Nip46SessionStore: failed to remove session', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Nip46SessionStore: encryption failed');
        }

        return base64_encode($iv . $ciphertext . $tag);
    }

    private function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Nip46SessionStore: invalid ciphertext');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, -self::TAG_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->aesKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Nip46SessionStore: decryption failed');
        }

        return $plaintext;
    }
}
