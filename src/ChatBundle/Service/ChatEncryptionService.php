<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

/**
 * AES-256-GCM encryption for custodial private keys.
 *
 * Each ciphertext uses a random 12-byte IV so identical plaintexts produce
 * different outputs. Stored as base64(iv . ciphertext . tag).
 */
class ChatEncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(string $encryptionKey)
    {
        // Derive a 32-byte key from the application secret
        $this->key = hash('sha256', $encryptionKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $ciphertext . $tag);
    }

    public function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Invalid ciphertext');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, -self::TAG_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — key may have been tampered with');
        }

        return $plaintext;
    }
}

