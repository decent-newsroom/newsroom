<?php

namespace App\Controller;

use App\Entity\UserUpload;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ImageUploadController extends AbstractController
{
    /**
     * Provider registry: id → [endpoint, protocol].
     * Protocol is either 'nip96' (multipart POST) or 'blossom' (PUT raw body).
     */
    private const PROVIDERS = [
        'nostrbuild'  => ['endpoint' => 'https://nostr.build/api/v2/nip96/upload',          'protocol' => 'nip96'],
        'nostrcheck'  => ['endpoint' => 'https://nostrcheck.me/api/v2/media',        'protocol' => 'nip96'],
        'sovbit'      => ['endpoint' => 'https://files.sovbit.host/api/v2/media',    'protocol' => 'nip96'],
        'blossomband' => ['endpoint' => 'https://blossom.band/upload',               'protocol' => 'blossom'],
    ];

    /** Human-readable labels for PHP upload error codes. */
    private const UPLOAD_ERRORS = [
        \UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize (%s)',
        \UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in HTML form',
        \UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        \UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        \UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
        \UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        \UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/image-upload/{provider}', name: 'api_image_upload', methods: ['POST'])]
    public function proxyUpload(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);

        if (!isset(self::PROVIDERS[$provider])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Unsupported provider'], 400);
        }

        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Nostr ')) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid Authorization header'], 401);
        }

        $file = $request->files->get('file');

        // Log everything we know about the incoming request + file
        $this->logger->info('[Upload] Incoming upload request', [
            'provider'       => $provider,
            'content_type'   => $request->headers->get('Content-Type'),
            'content_length' => $request->headers->get('Content-Length'),
            'has_file'       => $file !== null,
            'files_keys'     => array_keys($request->files->all()),
        ]);

        if (!$file) {
            $this->logger->warning('[Upload] No file in request', [
                'provider'       => $provider,
                'post_max_size'  => ini_get('post_max_size'),
                'upload_max'     => ini_get('upload_max_filesize'),
            ]);
            return new JsonResponse(['status' => 'error', 'message' => 'Missing file'], 400);
        }

        $this->logger->info('[Upload] File received', [
            'provider'      => $provider,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'client_mime'   => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'error_code'    => $file->getError(),
            'is_valid'      => $file->isValid(),
            'pathname'      => $file->getPathname(),
        ]);

        if (!$file->isValid()) {
            $errorCode = $file->getError();
            $reason = self::UPLOAD_ERRORS[$errorCode]
                ?? 'Unknown upload error (code ' . $errorCode . ')';

            // Interpolate PHP ini values for size-limit errors
            if ($errorCode === \UPLOAD_ERR_INI_SIZE) {
                $reason = sprintf($reason, ini_get('upload_max_filesize'));
            }

            $this->logger->error('[Upload] File upload not valid', [
                'provider'   => $provider,
                'error_code' => $errorCode,
                'reason'     => $reason,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Upload rejected by server: ' . $reason,
            ], 400);
        }

        $providerConfig = self::PROVIDERS[$provider];

        try {
            if ($providerConfig['protocol'] === 'blossom') {
                $response = $this->uploadBlossom($file, $authHeader, $providerConfig['endpoint']);
            } else {
                $response = $this->uploadNip96($file, $authHeader, $providerConfig['endpoint']);
            }

            // On success, persist the upload for the logged-in user
            $data = json_decode($response->getContent(), true);
            if (($data['status'] ?? null) === 'success' && !empty($data['url'])) {
                $this->saveUpload($provider, $data['url'], $file);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('[Upload] Proxy exception', [
                'provider'  => $provider,
                'exception' => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Proxy error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * NIP-96 upload: multipart/form-data POST.
     */
    private function uploadNip96(\Symfony\Component\HttpFoundation\File\UploadedFile $file, string $authHeader, string $endpoint): JsonResponse
    {
        $boundary = bin2hex(random_bytes(16));

        $fileContent = file_get_contents($file->getPathname());
        if ($fileContent === false) {
            return new JsonResponse(['status' => 'error', 'message' => 'Failed to read uploaded file'], 500);
        }

        $filename = $file->getClientOriginalName() ?: ('upload_' . date('Ymd_His'));
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"uploadtype\"\r\n\r\n";
        $body .= "media\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $headers = [
            'Authorization: ' . $authHeader,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];

        $responseBody = $this->doRequest('POST', $endpoint, $headers, $body);

        return $this->parseNip96Response($responseBody);
    }

    /**
     * Blossom (BUD-02) upload: PUT raw body with Content-Type.
     *
     * EXIF/GPS stripping is done client-side (Canvas re-draw) before the
     * SHA-256 hash is computed for the BUD-01 "x" tag.  The server MUST
     * forward the bytes unchanged so the hash still matches.
     */
    private function uploadBlossom(\Symfony\Component\HttpFoundation\File\UploadedFile $file, string $authHeader, string $endpoint): JsonResponse
    {
        // 20 MiB server-side size guard for blossom.band free tier
        $maxBytes = 20 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return new JsonResponse([
                'status' => 'error',
                'message' => sprintf('File too large for Blossom (max 20 MiB, got %.1f MiB)', $file->getSize() / 1024 / 1024),
            ], 413);
        }

        $pathname = $file->getPathname();
        $this->logger->info('[Upload][Blossom] Reading file from temp path', [
            'pathname'  => $pathname,
            'exists'    => file_exists($pathname),
            'size'      => $file->getSize(),
        ]);

        $fileContent = file_get_contents($pathname);
        if ($fileContent === false) {
            $this->logger->error('[Upload][Blossom] file_get_contents failed', ['pathname' => $pathname]);
            return new JsonResponse(['status' => 'error', 'message' => 'Failed to read uploaded file'], 500);
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';

        $this->logger->info('[Upload][Blossom] Forwarding to upstream', [
            'endpoint'     => $endpoint,
            'content_type' => $mimeType,
            'body_length'  => strlen($fileContent),
        ]);

        $headers = [
            'Authorization: ' . $authHeader,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($fileContent),
        ];

        $responseBody = $this->doRequest('PUT', $endpoint, $headers, $fileContent);

        $this->logger->info('[Upload][Blossom] Upstream response', [
            'status' => $responseBody['status'],
            'body'   => mb_substr($responseBody['body'], 0, 500),
        ]);

        return $this->parseBlossomResponse($responseBody);
    }


    /**
     * Execute an HTTP request to the upstream provider.
     */
    private function doRequest(string $method, string $url, array $headers, string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 60,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException('Upstream request failed');
        }

        $statusCode = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $hdr, $m)) {
                    $statusCode = (int) $m[1];
                    break;
                }
            }
        }

        return ['body' => $response, 'status' => $statusCode];
    }

    /**
     * Parse a NIP-96 JSON response and extract the URL.
     */
    private function parseNip96Response(array $response): JsonResponse
    {
        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON from provider', 'raw' => $response['body']], 502);
        }

        $statusCode = $response['status'];
        $isSuccess = (($json['status'] ?? null) === 'success')
            || (($json['success'] ?? null) === true)
            || ($statusCode >= 200 && $statusCode < 300);

        $imageUrl = null;
        if (isset($json['data']['url']) && is_string($json['data']['url'])) {
            $imageUrl = $json['data']['url'];
        } elseif (isset($json['url']) && is_string($json['url'])) {
            $imageUrl = $json['url'];
        } elseif (isset($json['result']['url']) && is_string($json['result']['url'])) {
            $imageUrl = $json['result']['url'];
        }

        if (!$imageUrl) {
            $tags = $json['nip94_event']['tags'] ?? null;
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    if (is_array($tag) && ($tag[0] ?? null) === 'url' && isset($tag[1])) {
                        $imageUrl = $tag[1];
                        break;
                    }
                }
            }
        }

        if ($isSuccess && $imageUrl) {
            return new JsonResponse(['status' => 'success', 'url' => $imageUrl]);
        }

        $message = $json['message'] ?? $json['error'] ?? $json['msg'] ?? 'Upload failed';
        return new JsonResponse(['status' => 'error', 'message' => $message, 'raw' => $json], $statusCode >= 400 ? $statusCode : 502);
    }

    /**
     * Parse a Blossom blob-descriptor JSON response and extract the URL.
     *
     * Blossom returns: { "url": "…", "sha256": "…", "size": …, "type": "…", "uploaded": … }
     */
    private function parseBlossomResponse(array $response): JsonResponse
    {
        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON from Blossom', 'raw' => $response['body']], 502);
        }

        $statusCode = $response['status'];

        if ($statusCode >= 400) {
            $message = $json['message'] ?? $json['error'] ?? 'Blossom upload failed';
            return new JsonResponse(['status' => 'error', 'message' => $message, 'raw' => $json], $statusCode);
        }

        $imageUrl = $json['url'] ?? null;
        if (!$imageUrl || !is_string($imageUrl)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No URL in Blossom response', 'raw' => $json], 502);
        }

        return new JsonResponse(['status' => 'success', 'url' => $imageUrl]);
    }

    /**
     * Persist a successful upload to the database for the current user.
     */
    private function saveUpload(string $provider, string $url, \Symfony\Component\HttpFoundation\File\UploadedFile $file): void
    {
        $user = $this->getUser();
        if (!$user) {
            return; // not logged in — nothing to save
        }

        try {
            $upload = new UserUpload();
            $upload->setNpub($user->getUserIdentifier());
            $upload->setUrl($url);
            $upload->setProvider($provider);
            $upload->setMimeType($file->getMimeType());
            $upload->setOriginalFilename($file->getClientOriginalName());
            $upload->setFileSize($file->getSize() ?: null);

            $this->entityManager->persist($upload);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Non-critical — don't break the upload response if DB save fails
        }
    }

    /**
     * List the current user's previously uploaded files.
     */
    #[Route('/api/user-uploads', name: 'api_user_uploads', methods: ['GET'])]
    public function listUploads(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $limit = min($request->query->getInt('limit', 50), 200);
        $offset = max($request->query->getInt('offset', 0), 0);
        $provider = $request->query->get('provider');

        /** @var \App\Repository\UserUploadRepository $repo */
        $repo = $this->entityManager->getRepository(UserUpload::class);
        $npub = $user->getUserIdentifier();

        $uploads = $repo->findByNpub($npub, $limit, $offset, $provider);
        $total = $repo->countByNpub($npub, $provider);

        return new JsonResponse([
            'uploads' => array_map(fn(UserUpload $u) => $u->toArray(), $uploads),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}

