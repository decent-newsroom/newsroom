<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Psr\Log\LoggerInterface;

/**
 * Stores ephemeral NIP-46 (remote signer) session credentials in Redis so the
 * relay gateway can sign NIP-42 AUTH events server-side without a browser roundtrip.
 *
 * The client private key is the *ephemeral* per-session key used only for
 * encrypting NIP-46 RPC messages (kind:24133) — it is NOT the user's nsec.
 * It is still encrypted at rest with the application's AES-256-GCM key.
 *
 * Callers:
 *   - POST /api/nostr-connect/session  (browser → server after NIP-46 login)
 *   - Nip46AuthSigner                  (gateway → read during AUTH challenge)
 *   - LogoutRelayCleanupListener       (delete on logout)
 */
class Nip46SessionService
{
    private const REDIS_PREFIX = 'nip46_session:';
    public const TTL_SECONDS = 28800; // 8 hours — matches Mercure cookie lifetime

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
     * Store a NIP-46 session for the given user (identified by their hex pubkey).
     *
     * @param string   $userPubkeyHex     User's Nostr pubkey (hex)
     * @param string   $clientPrivkeyHex  Ephemeral client private key (hex) — NOT the user's nsec
     * @param string   $bunkerPubkeyHex   Remote signer's pubkey (hex)
     * @param string[] $bunkerRelays      Relay URLs where the bunker is reachable
     */
    public function store(
        string $userPubkeyHex,
        string $clientPrivkeyHex,
        string $bunkerPubkeyHex,
        array $bunkerRelays,
    ): void {
        $data = json_encode([
            'clientPrivkeyEnc' => $this->encrypt($clientPrivkeyHex),
            'bunkerPubkey'     => $bunkerPubkeyHex,
            'bunkerRelays'     => array_values($bunkerRelays),
            'storedAt'         => time(),
        ]);

        try {
            $this->redis->set(
                self::REDIS_PREFIX . $userPubkeyHex,
                $data,
                ['ex' => self::TTL_SECONDS],
            );
            $this->logger->debug('Nip46SessionService: stored session', [
                'pubkey'         => substr($userPubkeyHex, 0, 8) . '...',
                'bunker_pubkey'  => substr($bunkerPubkeyHex, 0, 8) . '...',
                'relay_count'    => count($bunkerRelays),
            ]);
        } catch (\RedisException $e) {
            $this->logger->error('Nip46SessionService: failed to store session', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Retrieve a NIP-46 session for the given user.
     *
     * @return array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: string[]}|null
     */
    public function get(string $userPubkeyHex): ?array
    {
        try {
            $json = $this->redis->get(self::REDIS_PREFIX . $userPubkeyHex);
        } catch (\RedisException $e) {
            $this->logger->warning('Nip46SessionService: Redis error on get', [
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
            return [
                'clientPrivkeyHex' => $this->decrypt($data['clientPrivkeyEnc'] ?? ''),
                'bunkerPubkeyHex'  => $data['bunkerPubkey'] ?? '',
                'bunkerRelays'     => $data['bunkerRelays'] ?? [],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Nip46SessionService: failed to decrypt session', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Remove the NIP-46 session for the given user (called on logout).
     */
    public function remove(string $userPubkeyHex): void
    {
        try {
            $this->redis->del(self::REDIS_PREFIX . $userPubkeyHex);
        } catch (\RedisException $e) {
            $this->logger->warning('Nip46SessionService: failed to remove session', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─── Encryption helpers ───────────────────────────────────────────────────

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
            throw new \RuntimeException('Nip46SessionService: encryption failed');
        }

        return base64_encode($iv . $ciphertext . $tag);
    }

    private function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Nip46SessionService: invalid ciphertext');
        }

        $iv         = substr($data, 0, self::IV_LENGTH);
        $tag        = substr($data, -self::TAG_LENGTH);
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
            throw new \RuntimeException('Nip46SessionService: decryption failed');
        }

        return $plaintext;
    }
}

