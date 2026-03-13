<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Dto\NormalizedMedia;
use Psr\Log\LoggerInterface;

/**
 * Blossom (NIP-B7) media provider adapter.
 *
 * Handles upload via PUT /upload, optional listing via GET /list/<pubkey>,
 * and delete via DELETE /<sha256>.
 *
 * @see §8.1 of multimedia-manager spec
 */
class BlossomMediaProvider implements MediaProviderInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $baseUrl,
        private readonly bool $listEnabled,
        private readonly LoggerInterface $logger,
    ) {}

    public function getId(): string { return $this->id; }
    public function getLabel(): string { return $this->label; }
    public function getProtocol(): string { return 'blossom'; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function supportsUpload(): bool { return true; }
    public function supportsListOwn(): bool { return $this->listEnabled; }
    public function supportsListByPubkey(): bool { return $this->listEnabled; }
    public function supportsDelete(): bool { return true; }

    public function getAcceptedMimePrefixes(): array
    {
        return ['image/', 'video/'];
    }

    public function upload(string $filePath, string $fileName, string $mimeType, string $authHeader): NormalizedMedia
    {
        $url = rtrim($this->baseUrl, '/') . '/upload';
        $fileContent = file_get_contents($filePath);

        if ($fileContent === false) {
            throw new \RuntimeException('Failed to read file: ' . $filePath);
        }

        $headers = [
            'Authorization: ' . $authHeader,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($fileContent),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => implode("\r\n", $headers),
                'content' => $fileContent,
                'ignore_errors' => true,
                'timeout' => 60,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException('Blossom upload request failed');
        }

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
        if ($statusCode >= 400) {
            throw new \RuntimeException('Blossom upload failed with status ' . $statusCode . ': ' . $response);
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid JSON from Blossom upload');
        }

        $this->logger->info('Blossom upload successful', ['provider' => $this->id, 'sha256' => $json['sha256'] ?? 'unknown']);

        return $this->normalizeBlobDescriptor($json);
    }

    public function listAssets(string $pubkey, ?string $cursor = null, int $limit = 50): array
    {
        if (!$this->listEnabled) {
            return [];
        }

        $url = rtrim($this->baseUrl, '/') . '/list/' . $pubkey;
        $params = [];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        $params['limit'] = $limit;
        $url .= '?' . http_build_query($params);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->logger->warning('Blossom list request failed', ['provider' => $this->id, 'pubkey' => $pubkey]);
            return [];
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            return [];
        }

        $results = [];
        foreach ($json as $blob) {
            if (is_array($blob) && isset($blob['url'])) {
                $results[] = $this->normalizeBlobDescriptor($blob);
            }
        }

        return $results;
    }

    public function deleteAsset(string $hash, string $authHeader): bool
    {
        $url = rtrim($this->baseUrl, '/') . '/' . $hash;

        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => 'Authorization: ' . $authHeader,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = $this->extractStatusCode($http_response_header ?? []);

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->logger->info('Blossom asset deleted', ['provider' => $this->id, 'hash' => $hash]);
            return true;
        }

        $this->logger->warning('Blossom delete failed', ['provider' => $this->id, 'hash' => $hash, 'status' => $statusCode]);
        return false;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'protocol' => $this->getProtocol(),
            'base_url' => $this->baseUrl,
            'supports_upload' => $this->supportsUpload(),
            'supports_list_own' => $this->supportsListOwn(),
            'supports_list_by_pubkey' => $this->supportsListByPubkey(),
            'supports_delete' => $this->supportsDelete(),
            'accepted_mime_prefixes' => $this->getAcceptedMimePrefixes(),
        ];
    }

    /**
     * Normalize a Blossom Blob Descriptor into a NormalizedMedia.
     * Uses nip94 field when present per spec §8.1 rule.
     */
    private function normalizeBlobDescriptor(array $blob): NormalizedMedia
    {
        // If nip94 field is present, use it as primary metadata source
        if (isset($blob['nip94']) && is_array($blob['nip94'])) {
            return $this->normalizeFromNip94Tags($blob['nip94'], $blob);
        }

        $mime = $blob['type'] ?? $blob['mime'] ?? null;

        return new NormalizedMedia(
            sourceType: 'asset',
            providerId: $this->id,
            primaryUrl: $blob['url'] ?? '',
            mime: $mime,
            sha256: $blob['sha256'] ?? null,
            size: isset($blob['size']) ? (int) $blob['size'] : null,
            uploadedAt: isset($blob['uploaded']) ? (int) $blob['uploaded'] : null,
            rawProviderMeta: $blob,
        );
    }

    /**
     * Normalize from NIP-94 tags structure returned by Blossom.
     */
    private function normalizeFromNip94Tags(array $nip94, array $blob): NormalizedMedia
    {
        $tagMap = [];
        $tags = $nip94['tags'] ?? $nip94;
        foreach ($tags as $tag) {
            if (is_array($tag) && count($tag) >= 2) {
                $tagMap[$tag[0]] = $tag[1];
            }
        }

        return new NormalizedMedia(
            sourceType: 'asset',
            providerId: $this->id,
            primaryUrl: $tagMap['url'] ?? $blob['url'] ?? '',
            mime: $tagMap['m'] ?? $blob['type'] ?? null,
            sha256: $tagMap['x'] ?? $blob['sha256'] ?? null,
            originalSha256: $tagMap['ox'] ?? null,
            size: isset($tagMap['size']) ? (int) $tagMap['size'] : (isset($blob['size']) ? (int) $blob['size'] : null),
            dimensions: $tagMap['dim'] ?? null,
            alt: $tagMap['alt'] ?? null,
            blurhash: $tagMap['blurhash'] ?? null,
            thumbUrl: $tagMap['thumb'] ?? null,
            uploadedAt: isset($blob['uploaded']) ? (int) $blob['uploaded'] : null,
            rawProviderMeta: $blob,
        );
    }

    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $hdr) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /**
     * Strip EXIF metadata (including GPS) by re-encoding the image via GD.
     *
     * Returns the re-encoded binary string, or false if unsupported.
     */
    private function stripExifIfImage(string $filePath, string $mimeType): string|false
    {
        $supportedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mimeType, $supportedTypes, true)) {
            return false;
        }

        $image = @imagecreatefromstring(file_get_contents($filePath));
        if (!$image) {
            return false;
        }

        ob_start();
        $ok = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, null, 92),
            'image/png'  => imagepng($image, null, 9),
            'image/webp' => imagewebp($image, null, 92),
            'image/gif'  => imagegif($image),
            default      => false,
        };
        $data = ob_get_clean();
        imagedestroy($image);

        return $ok ? $data : false;
    }
}

