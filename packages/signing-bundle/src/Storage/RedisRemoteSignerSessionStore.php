<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Storage;

use DecentNewsroom\SigningBundle\Contract\RemoteSignerSessionStoreInterface;
use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;
use Psr\Log\LoggerInterface;

final class RedisRemoteSignerSessionStore implements RemoteSignerSessionStoreInterface
{
    public const DEFAULT_TTL_SECONDS = 28800;

    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $aesKey;

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        string $encryptionKey,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly string $keyPrefix = 'nip46_session:',
    ) {
        $this->aesKey = hash('sha256', $encryptionKey, true);
    }

    public function store(string $subjectId, RemoteSignerSession $session): void
    {
        $data = json_encode($session->toStorageArray($this->encrypt($session->clientPrivkeyHex())));

        if ($data === false) {
            throw new \RuntimeException('NIP-46 remote signer session data could not be encoded.');
        }

        try {
            $this->redis->set(
                $this->key($subjectId),
                $data,
                ['ex' => $this->ttlSeconds],
            );
            $this->logger->debug('Remote signer session stored', [
                'subject' => $this->redact($subjectId),
                'remote_signer_pubkey' => $this->redact($session->remoteSignerPubkeyHex()),
                'relay_count' => count($session->relayUrls()),
            ]);
        } catch (\RedisException $e) {
            $this->logger->error('Remote signer session store failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function has(string $subjectId): bool
    {
        try {
            return (int) $this->redis->exists($this->key($subjectId)) > 0;
        } catch (\RedisException $e) {
            $this->logger->warning('Remote signer session exists check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function get(string $subjectId): ?RemoteSignerSession
    {
        try {
            $json = $this->redis->get($this->key($subjectId));
        } catch (\RedisException $e) {
            $this->logger->warning('Remote signer session read failed', [
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

            return RemoteSignerSession::fromStorageArray($data, $clientPrivkeyHex);
        } catch (\Throwable $e) {
            $this->logger->warning('Remote signer session decode failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function refresh(string $subjectId, int $ttlSeconds): bool
    {
        $key = $this->key($subjectId);

        try {
            if ((int) $this->redis->exists($key) <= 0) {
                return false;
            }

            return (bool) $this->redis->expire($key, max(1, $ttlSeconds));
        } catch (\RedisException $e) {
            $this->logger->warning('Remote signer session TTL refresh failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function remove(string $subjectId): void
    {
        try {
            $this->redis->del($this->key($subjectId));
        } catch (\RedisException $e) {
            $this->logger->warning('Remote signer session removal failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function key(string $subjectId): string
    {
        return $this->keyPrefix.$subjectId;
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
            throw new \RuntimeException('Remote signer session encryption failed.');
        }

        return base64_encode($iv.$ciphertext.$tag);
    }

    private function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Remote signer session ciphertext is invalid.');
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
            throw new \RuntimeException('Remote signer session decryption failed.');
        }

        return $plaintext;
    }

    private function redact(string $value): string
    {
        return substr($value, 0, 8).'...';
    }
}
