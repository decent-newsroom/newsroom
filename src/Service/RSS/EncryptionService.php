<?php

declare(strict_types=1);

namespace App\Service\RSS;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class EncryptionService
{
    private string $encryptionKey;

    public function __construct(ParameterBagInterface $bag)
    {
        // Retrieve the symmetric encryption key securely from Symfony Secrets
        $this->encryptionKey = $bag->get('encryption_key');
    }

    public function encrypt(string $data): string
    {
        return openssl_encrypt($data, 'aes-256-cbc', $this->encryptionKey, 0, $this->getIv());
    }

    public function decrypt(string $encryptedData): string
    {
        return openssl_decrypt($encryptedData, 'aes-256-cbc', $this->encryptionKey, 0, $this->getIv());
    }

    private function getIv(): string
    {
        return substr(hash('sha256', $this->encryptionKey), 0, 16); // AES-256 requires a 16-byte IV
    }
}
