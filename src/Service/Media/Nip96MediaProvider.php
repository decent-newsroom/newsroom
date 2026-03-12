<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Dto\NormalizedMedia;
use Psr\Log\LoggerInterface;

/**
 * NIP-96 HTTP file storage media provider adapter.
 *
 * Handles upload via POST $api_url, listing for authenticated user,
 * delete by original hash, and NIP-98 auth.
 *
 * @see §8.2 of multimedia-manager spec
 */
class Nip96MediaProvider implements MediaProviderInterface
{
    private ?array $serverConfig = null;

    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $baseUrl,
        private readonly string $apiUrl,
        private readonly LoggerInterface $logger,
    ) {}

    public function getId(): string { return $this->id; }
    public function getLabel(): string { return $this->label; }
    public function getProtocol(): string { return 'nip96'; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function supportsUpload(): bool { return true; }
    public function supportsListOwn(): bool { return true; }
    public function supportsListByPubkey(): bool { return false; }
    public function supportsDelete(): bool { return true; }

    public function getAcceptedMimePrefixes(): array
    {
        return ['image/', 'video/'];
    }

    public function upload(string $filePath, string $fileName, string $mimeType, string $authHeader): NormalizedMedia
    {
        $endpoint = $this->apiUrl;

        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            throw new \RuntimeException('Failed to read file: ' . $filePath);
        }

        $boundary = bin2hex(random_bytes(16));
        $body = '';

        // uploadtype field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"uploadtype\"\r\n\r\n";
        $body .= "media\r\n";

        // file field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $headers = [
            'Authorization: ' . $authHeader,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 60,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            throw new \RuntimeException('NIP-96 upload request failed');
        }

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
        $json = json_decode($response, true);

        if (!is_array($json)) {
            throw new \RuntimeException('Invalid JSON from NIP-96 upload');
        }

        // Handle delayed processing
        if (isset($json['processing_url'])) {
            $this->logger->info('NIP-96 upload accepted for processing', [
                'provider' => $this->id,
                'processing_url' => $json['processing_url'],
            ]);
            // Return a partial result; the client can poll processing_url
            return new NormalizedMedia(
                sourceType: 'asset',
                providerId: $this->id,
                rawProviderMeta: $json,
            );
        }

        // Check for errors
        if ($statusCode >= 400) {
            $message = $json['message'] ?? $json['error'] ?? 'Upload failed';
            throw new \RuntimeException('NIP-96 upload failed (' . $statusCode . '): ' . $message);
        }

        $this->logger->info('NIP-96 upload successful', ['provider' => $this->id]);

        return $this->normalizeUploadResponse($json);
    }

    public function listAssets(string $pubkey, ?string $cursor = null, int $limit = 50): array
    {
        $page = $cursor !== null ? (int) $cursor : 0;
        $url = $this->apiUrl . '?' . http_build_query(['page' => $page, 'count' => $limit]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->logger->warning('NIP-96 list request failed', ['provider' => $this->id]);
            return [];
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            return [];
        }

        $files = $json['files'] ?? $json['data'] ?? [];
        $results = [];
        foreach ($files as $file) {
            if (is_array($file)) {
                $results[] = $this->normalizeFileEntry($file);
            }
        }

        return $results;
    }

    public function deleteAsset(string $hash, string $authHeader): bool
    {
        $url = $this->apiUrl . '/' . $hash;

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
            $this->logger->info('NIP-96 asset deleted', ['provider' => $this->id, 'hash' => $hash]);
            return true;
        }

        $this->logger->warning('NIP-96 delete failed', ['provider' => $this->id, 'hash' => $hash, 'status' => $statusCode]);
        return false;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'protocol' => $this->getProtocol(),
            'base_url' => $this->baseUrl,
            'api_url' => $this->apiUrl,
            'supports_upload' => $this->supportsUpload(),
            'supports_list_own' => $this->supportsListOwn(),
            'supports_list_by_pubkey' => $this->supportsListByPubkey(),
            'supports_delete' => $this->supportsDelete(),
            'accepted_mime_prefixes' => $this->getAcceptedMimePrefixes(),
        ];
    }

    /**
     * Normalize NIP-96 upload response using nip94_event.tags when present.
     */
    private function normalizeUploadResponse(array $json): NormalizedMedia
    {
        $nip94Tags = $json['nip94_event']['tags'] ?? null;
        if (is_array($nip94Tags)) {
            return $this->normalizeFromNip94Tags($nip94Tags, $json);
        }

        // Fallback: extract URL from common locations
        $imageUrl = $json['data']['url'] ?? $json['url'] ?? $json['result']['url'] ?? null;

        return new NormalizedMedia(
            sourceType: 'asset',
            providerId: $this->id,
            primaryUrl: $imageUrl ?? '',
            rawProviderMeta: $json,
        );
    }

    /**
     * Normalize from NIP-94 tags array.
     */
    private function normalizeFromNip94Tags(array $tags, array $rawResponse): NormalizedMedia
    {
        $tagMap = [];
        $fallbacks = [];
        $images = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            $key = $tag[0];
            $value = $tag[1];

            if ($key === 'fallback') {
                $fallbacks[] = $value;
            } elseif ($key === 'image') {
                $images[] = $value;
            } else {
                $tagMap[$key] = $value;
            }
        }

        return new NormalizedMedia(
            sourceType: 'asset',
            providerId: $this->id,
            primaryUrl: $tagMap['url'] ?? '',
            mime: $tagMap['m'] ?? null,
            sha256: $tagMap['x'] ?? null,
            originalSha256: $tagMap['ox'] ?? null,
            size: isset($tagMap['size']) ? (int) $tagMap['size'] : null,
            dimensions: $tagMap['dim'] ?? null,
            alt: $tagMap['alt'] ?? null,
            blurhash: $tagMap['blurhash'] ?? null,
            thumbUrl: $tagMap['thumb'] ?? null,
            fallbackUrls: $fallbacks,
            previewImages: $images,
            rawProviderMeta: $rawResponse,
        );
    }

    /**
     * Normalize a file entry from the NIP-96 list response.
     */
    private function normalizeFileEntry(array $file): NormalizedMedia
    {
        // NIP-96 list returns NIP-94-like file entries
        $tags = $file['tags'] ?? [];
        if (!empty($tags)) {
            return $this->normalizeFromNip94Tags($tags, $file);
        }

        return new NormalizedMedia(
            sourceType: 'asset',
            providerId: $this->id,
            primaryUrl: $file['url'] ?? '',
            mime: $file['type'] ?? $file['mime'] ?? null,
            sha256: $file['sha256'] ?? $file['x'] ?? null,
            size: isset($file['size']) ? (int) $file['size'] : null,
            rawProviderMeta: $file,
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
}

