<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Dto\NormalizedMedia;

/**
 * Common interface for media upload providers (Blossom / NIP-96).
 *
 * @see §5.1 of multimedia-manager spec
 */
interface MediaProviderInterface
{
    public function getId(): string;

    public function getLabel(): string;

    /** Protocol family: 'blossom' or 'nip96'. */
    public function getProtocol(): string;

    public function getBaseUrl(): string;

    public function supportsUpload(): bool;

    public function supportsListOwn(): bool;

    public function supportsListByPubkey(): bool;

    public function supportsDelete(): bool;

    /** @return string[] Accepted MIME prefixes e.g. ['image/', 'video/'] */
    public function getAcceptedMimePrefixes(): array;

    public function upload(string $filePath, string $fileName, string $mimeType, string $authHeader): NormalizedMedia;

    /** @return NormalizedMedia[] */
    public function listAssets(string $pubkey, ?string $cursor = null, int $limit = 50): array;

    public function deleteAsset(string $hash, string $authHeader): bool;

    public function toArray(): array;
}

