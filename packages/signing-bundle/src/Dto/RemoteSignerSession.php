<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Dto;

final class RemoteSignerSession
{
    /** @var list<string> */
    private array $relayUrls;

    /**
     * @param list<string> $relayUrls
     */
    public function __construct(
        private readonly string $clientPrivkeyHex,
        private readonly string $remoteSignerPubkeyHex,
        array $relayUrls,
        private readonly ?string $userPubkeyHex = null,
        private readonly ?string $secret = null,
        private readonly int $storedAt = 0,
        private readonly ?int $lastSuccessAt = null,
        private readonly ?int $lastFailureAt = null,
        private readonly string $method = 'nip46',
    ) {
        $this->relayUrls = self::normalizeRelayUrls($relayUrls);
    }

    /**
     * @param list<string> $relayUrls
     */
    public static function forBunker(
        string $clientPrivkeyHex,
        string $remoteSignerPubkeyHex,
        array $relayUrls,
        ?string $userPubkeyHex = null,
        ?string $secret = null,
    ): self {
        return new self(
            $clientPrivkeyHex,
            $remoteSignerPubkeyHex,
            $relayUrls,
            $userPubkeyHex,
            $secret,
            time(),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromLegacyArray(array $data, ?string $userPubkeyHex = null): self
    {
        $relays = $data['bunkerRelays'] ?? [];
        if (!is_array($relays)) {
            $relays = [];
        }

        return self::forBunker(
            (string) ($data['clientPrivkeyHex'] ?? ''),
            (string) ($data['bunkerPubkeyHex'] ?? $data['remoteSignerPubkeyHex'] ?? ''),
            array_values(array_filter($relays, 'is_string')),
            $userPubkeyHex,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromStorageArray(array $data, string $clientPrivkeyHex): self
    {
        $relays = $data['relayUrls'] ?? $data['bunkerRelays'] ?? [];
        if (!is_array($relays)) {
            $relays = [];
        }

        return new self(
            $clientPrivkeyHex,
            (string) ($data['remoteSignerPubkeyHex'] ?? $data['bunkerPubkey'] ?? ''),
            array_values(array_filter($relays, 'is_string')),
            isset($data['userPubkeyHex']) ? (string) $data['userPubkeyHex'] : null,
            isset($data['secret']) ? (string) $data['secret'] : null,
            (int) ($data['storedAt'] ?? time()),
            isset($data['lastSuccessAt']) ? (int) $data['lastSuccessAt'] : null,
            isset($data['lastFailureAt']) ? (int) $data['lastFailureAt'] : null,
            (string) ($data['method'] ?? 'nip46'),
        );
    }

    public function clientPrivkeyHex(): string
    {
        return $this->clientPrivkeyHex;
    }

    public function remoteSignerPubkeyHex(): string
    {
        return $this->remoteSignerPubkeyHex;
    }

    /** @return list<string> */
    public function relayUrls(): array
    {
        return $this->relayUrls;
    }

    public function userPubkeyHex(): ?string
    {
        return $this->userPubkeyHex;
    }

    public function secret(): ?string
    {
        return $this->secret;
    }

    public function storedAt(): int
    {
        return $this->storedAt > 0 ? $this->storedAt : time();
    }

    public function lastSuccessAt(): ?int
    {
        return $this->lastSuccessAt;
    }

    public function lastFailureAt(): ?int
    {
        return $this->lastFailureAt;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function withLastSuccessAt(int $timestamp): self
    {
        return new self(
            $this->clientPrivkeyHex,
            $this->remoteSignerPubkeyHex,
            $this->relayUrls,
            $this->userPubkeyHex,
            $this->secret,
            $this->storedAt(),
            $timestamp,
            $this->lastFailureAt,
            $this->method,
        );
    }

    public function withLastFailureAt(int $timestamp): self
    {
        return new self(
            $this->clientPrivkeyHex,
            $this->remoteSignerPubkeyHex,
            $this->relayUrls,
            $this->userPubkeyHex,
            $this->secret,
            $this->storedAt(),
            $this->lastSuccessAt,
            $timestamp,
            $this->method,
        );
    }

    /**
     * @return array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: list<string>}
     */
    public function toLegacyArray(): array
    {
        return [
            'clientPrivkeyHex' => $this->clientPrivkeyHex,
            'bunkerPubkeyHex' => $this->remoteSignerPubkeyHex,
            'bunkerRelays' => $this->relayUrls,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(string $encryptedClientPrivkey): array
    {
        return [
            'clientPrivkeyEnc' => $encryptedClientPrivkey,
            'remoteSignerPubkeyHex' => $this->remoteSignerPubkeyHex,
            'bunkerPubkey' => $this->remoteSignerPubkeyHex,
            'relayUrls' => $this->relayUrls,
            'bunkerRelays' => $this->relayUrls,
            'userPubkeyHex' => $this->userPubkeyHex,
            'secret' => $this->secret,
            'storedAt' => $this->storedAt(),
            'lastSuccessAt' => $this->lastSuccessAt,
            'lastFailureAt' => $this->lastFailureAt,
            'method' => $this->method,
        ];
    }

    /**
     * @param list<string> $relayUrls
     * @return list<string>
     */
    private static function normalizeRelayUrls(array $relayUrls): array
    {
        $normalized = [];
        foreach ($relayUrls as $relayUrl) {
            $relayUrl = trim($relayUrl);
            if ($relayUrl === '') {
                continue;
            }

            if (!str_starts_with($relayUrl, 'ws://') && !str_starts_with($relayUrl, 'wss://')) {
                continue;
            }

            $normalized[strtolower($relayUrl)] = $relayUrl;
        }

        return array_values($normalized);
    }
}
