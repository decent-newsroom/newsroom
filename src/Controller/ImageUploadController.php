<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ImageUploadController extends AbstractController
{
    #[Route('/api/image-upload/{provider}', name: 'api_image_upload', methods: ['POST'])]
    public function proxyUpload(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        $endpoints = [
            'nostrbuild' => 'https://nostr.build/nip96/upload',
            'nostrcheck' => 'https://nostrcheck.me/api/v2/media',
            'sovbit'     => 'https://files.sovbit.host/api/v2/media',
        ];

        if (!isset($endpoints[$provider])) {
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

        $endpoint = $endpoints[$provider];

        try {
            $boundary = bin2hex(random_bytes(16));

            $fields = [
                'uploadtype' => 'media',
            ];

            $body = '';
            foreach ($fields as $name => $value) {
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
                $body .= $value . "\r\n";
            }

            $fileContent = file_get_contents($file->getPathname());
            if ($fileContent === false) {
                return new JsonResponse(['status' => 'error', 'message' => 'Failed to read uploaded file'], 500);
            }

            $filename = $file->getClientOriginalName() ?: ('upload_' . date('Ymd_His'));
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
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
                    'timeout' => 30,
                ],
            ]);

            $responseBody = @file_get_contents($endpoint, false, $context);
            if ($responseBody === false) {
                return new JsonResponse(['status' => 'error', 'message' => 'Upstream request failed'], 502);
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

            $json = json_decode($responseBody, true);
            if (!is_array($json)) {
                return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON from provider', 'raw' => $responseBody], 502);
            }

            $isSuccess = (($json['status'] ?? null) === 'success') || (($json['success'] ?? null) === true) || ($statusCode >= 200 && $statusCode < 300);

            $imageUrl = null;
            // Common locations: data.url, url, result.url, nip94_event.tags -> ['url', '...']
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
            return new JsonResponse(['status' => 'error', 'message' => $message, 'provider' => $provider, 'raw' => $json], $statusCode >= 400 ? $statusCode : 502);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Proxy error: ' . $e->getMessage(),
            ], 500);
        }
    }
}

