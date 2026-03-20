<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatUser;
use swentel\nostr\Key\Key;

/**
 * Generates and decrypts custodial Nostr keypairs for chat users.
 * Private keys are encrypted at rest and only decrypted transiently for signing.
 */
class ChatKeyManager
{
    private Key $key;

    public function __construct(
        private readonly ChatEncryptionService $encryption,
    ) {
        $this->key = new Key();
    }

    /**
     * Generate a new Nostr keypair and encrypt the private key.
     *
     * @return array{pubkey: string, encryptedPrivateKey: string}
     */
    public function generateKeypair(): array
    {
        $privateKey = bin2hex(random_bytes(32));
        $publicKey = $this->key->getPublicKey($privateKey);

        return [
            'pubkey' => $publicKey,
            'encryptedPrivateKey' => $this->encryption->encrypt($privateKey),
        ];
    }

    /**
     * Decrypt a ChatUser's private key. The caller MUST NOT log or persist the return value.
     */
    public function decryptPrivateKey(ChatUser $user): string
    {
        return $this->encryption->decrypt($user->getEncryptedPrivateKey());
    }
}

