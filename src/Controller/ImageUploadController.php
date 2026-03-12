<?php

namespace App\Controller;

use App\Entity\UserUpload;
use Doctrine\ORM\EntityManagerInterface;
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
        'nostrbuild'  => ['endpoint' => 'https://nostr.build/nip96/upload',          'protocol' => 'nip96'],
        'nostrcheck'  => ['endpoint' => 'https://nostrcheck.me/api/v2/media',        'protocol' => 'nip96'],
        'sovbit'      => ['endpoint' => 'https://files.sovbit.host/api/v2/media',    'protocol' => 'nip96'],
        'blossomband' => ['endpoint' => 'https://blossom.band/upload',               'protocol' => 'blossom'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
        if (!$file) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing file'], 400);
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
     */
    private function uploadBlossom(\Symfony\Component\HttpFoundation\File\UploadedFile $file, string $authHeader, string $endpoint): JsonResponse
    {
        $fileContent = file_get_contents($file->getPathname());
        if ($fileContent === false) {
            return new JsonResponse(['status' => 'error', 'message' => 'Failed to read uploaded file'], 500);
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';

        $headers = [
            'Authorization: ' . $authHeader,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($fileContent),
        ];

        $responseBody = $this->doRequest('PUT', $endpoint, $headers, $fileContent);

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

        /** @var \App\Repository\UserUploadRepository $repo */
        $repo = $this->entityManager->getRepository(UserUpload::class);
        $uploads = $repo->findByNpub($user->getUserIdentifier(), $limit, $offset);
        $total = $repo->countByNpub($user->getUserIdentifier());

        return new JsonResponse([
            'uploads' => array_map(fn(UserUpload $u) => $u->toArray(), $uploads),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}

