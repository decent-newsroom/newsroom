<?php

declare(strict_types=1);

namespace App\Signing;

use DecentNewsroom\SigningBundle\Contract\RemoteSignerSessionStoreInterface;
use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;
use Psr\Log\LoggerInterface;

final readonly class RedisRemoteSignerSessionStore implements RemoteSignerSessionStoreInterface
{
    private const REDIS_PREFIX = 'nip46_session:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $aesKey;

    public function __construct(
        private \Redis $redis,
        private LoggerInterface $logger,
        string $encryptionKey,
        private int $ttlSeconds,
    ) {
        $this->aesKey = hash('sha256', $encryptionKey, true);
    }

    public function store(string $subjectId, RemoteSignerSession $session): void
    {
        $payload = $session->toStorageArray($this->encrypt($session->clientPrivkeyHex()));
        $payload['userPubkeyHex'] = $subjectId;

        $stored = $this->redis->set(
            $this->key($subjectId),
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['ex' => $this->ttlSeconds],
        );

        if ($stored !== true) {
            throw new \RuntimeException('Unable to store remote signer session.');
        }
    }

    public function has(string $subjectId): bool
    {
        return $this->redis->exists($this->key($subjectId)) > 0;
    }

    public function get(string $subjectId): ?RemoteSignerSession
    {
        $json = $this->redis->get($this->key($subjectId));
        if (!is_string($json) || $json === '') {
            return null;
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !isset($payload['clientPrivkeyEnc']) || !is_string($payload['clientPrivkeyEnc'])) {
                throw new \UnexpectedValueException('Remote signer session payload is invalid.');
            }

            return RemoteSignerSession::fromStorageArray(
                $payload,
                $this->decrypt($payload['clientPrivkeyEnc']),
            );
        } catch (\JsonException|\RuntimeException $exception) {
            $this->logger->warning('Unable to read remote signer session.', [
                'subject_id' => substr($subjectId, 0, 8) . '...',
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function refresh(string $subjectId, int $ttlSeconds): bool
    {
        return $this->redis->expire($this->key($subjectId), $ttlSeconds);
    }

    public function remove(string $subjectId): void
    {
        $this->redis->del($this->key($subjectId));
    }

    private function key(string $subjectId): string
    {
        return self::REDIS_PREFIX . $subjectId;
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

        return base64_encode($iv . $ciphertext . $tag);
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
}
