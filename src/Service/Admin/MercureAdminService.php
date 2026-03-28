<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service providing Mercure hub diagnostics for the admin dashboard.
 *
 * Capabilities:
 *  - Environment / configuration overview
 *  - Publish a test message and report success/failure + latency
 *  - Fetch active subscriptions from the Mercure subscriptions API
 *  - Inspect the BoltDB transport file (size, mtime)
 */
class MercureAdminService
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $mercureUrl,
        private readonly string $mercurePublicUrl,
        private readonly string $mercureJwtSecret,
    ) {
    }

    // ---------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------

    /**
     * Return a summary of the Mercure-related environment configuration.
     */
    public function getConfiguration(): array
    {
        return [
            'internal_url' => $this->mercureUrl,
            'public_url' => $this->mercurePublicUrl,
            'jwt_secret_set' => $this->mercureJwtSecret !== '' && $this->mercureJwtSecret !== '(not set)',
            'bolt_db_path' => '/data/mercure.db',
            'cleanup_frequency' => 300,
            'write_timeout' => '10s',
            'dispatch_timeout' => '5s',
            'anonymous_subscribers' => true,
            'subscriptions_api' => true,
        ];
    }

    // ---------------------------------------------------------------
    // Hub connectivity test
    // ---------------------------------------------------------------

    /**
     * Attempt to reach the Mercure hub's well-known URL and report HTTP
     * status + response time.
     */
    public function testHubConnectivity(): array
    {
        $url = rtrim($this->mercureUrl, '/');

        $start = microtime(true);
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5,
                'max_redirects' => 0,
            ]);
            $statusCode = $response->getStatusCode();
            $latency = round((microtime(true) - $start) * 1000, 1);

            return [
                'reachable' => $statusCode < 500,
                'status_code' => $statusCode,
                'latency_ms' => $latency,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 1);

            return [
                'reachable' => false,
                'status_code' => null,
                'latency_ms' => $latency,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ---------------------------------------------------------------
    // Publish test
    // ---------------------------------------------------------------

    /**
     * Publish a test update to the given topic and return timing/result info.
     */
    public function publishTest(string $topic = '/test/admin-ping'): array
    {
        $data = json_encode([
            'source' => 'mercure-admin',
            'message' => 'Test from admin dashboard',
            'timestamp' => time(),
        ]);

        $start = microtime(true);
        try {
            $update = new Update($topic, $data, false);
            $id = $this->hub->publish($update);
            $latency = round((microtime(true) - $start) * 1000, 1);

            return [
                'success' => true,
                'id' => $id,
                'topic' => $topic,
                'latency_ms' => $latency,
                'error' => null,
                'tested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 1);
            $this->logger->error('Mercure admin publish test failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'id' => null,
                'topic' => $topic,
                'latency_ms' => $latency,
                'error' => $e->getMessage(),
                'tested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
        }
    }

    // ---------------------------------------------------------------
    // Active subscriptions (Mercure subscriptions API)
    // ---------------------------------------------------------------

    /**
     * Fetch active subscriptions from the Mercure hub subscriptions endpoint.
     *
     * @see https://mercure.rocks/spec#subscription
     */
    public function getActiveSubscriptions(): array
    {
        $url = rtrim($this->mercureUrl, '/') . '/subscriptions';

        try {
            $token = $this->createSubscriptionJwt();
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                return [
                    'available' => false,
                    'error' => sprintf('HTTP %d from subscriptions endpoint', $statusCode),
                    'subscriptions' => [],
                    'total' => 0,
                ];
            }

            $payload = json_decode($response->getContent(), true);
            $subscriptions = $payload['subscriptions'] ?? [];

            // Group by topic
            $byTopic = [];
            foreach ($subscriptions as $sub) {
                $topic = $sub['topic'] ?? '(unknown)';
                $byTopic[$topic] = ($byTopic[$topic] ?? 0) + 1;
            }

            return [
                'available' => true,
                'error' => null,
                'subscriptions' => $subscriptions,
                'by_topic' => $byTopic,
                'total' => count($subscriptions),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch Mercure subscriptions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'error' => $e->getMessage(),
                'subscriptions' => [],
                'total' => 0,
            ];
        }
    }

    // ---------------------------------------------------------------
    // BoltDB transport info
    // ---------------------------------------------------------------

    /**
     * Get information about the BoltDB file used by the Mercure transport.
     */
    public function getBoltDbInfo(): array
    {
        $path = '/data/mercure.db';

        if (!file_exists($path)) {
            return [
                'exists' => false,
                'path' => $path,
                'size_bytes' => 0,
                'size_human' => '—',
                'modified_at' => null,
                'age_seconds' => null,
            ];
        }

        $size = filesize($path);
        $mtime = filemtime($path);
        $now = time();

        return [
            'exists' => true,
            'path' => $path,
            'size_bytes' => $size,
            'size_human' => $this->humanFileSize($size),
            'modified_at' => (new \DateTimeImmutable())->setTimestamp($mtime)->format('Y-m-d H:i:s'),
            'age_seconds' => $now - $mtime,
        ];
    }

    // ---------------------------------------------------------------
    // Known topics registry
    // ---------------------------------------------------------------

    /**
     * Return the list of known Mercure topic patterns used by the application.
     */
    public function getKnownTopicPatterns(): array
    {
        return [
            [
                'pattern' => '/articles/{pubkey}',
                'description' => 'Author article updates (FetchAuthorArticlesHandler)',
                'publisher' => 'FetchAuthorArticlesHandler',
            ],
            [
                'pattern' => '/author/{pubkey}/{contentType}',
                'description' => 'Author content updates (articles, drafts, media, highlights, bookmarks, interests)',
                'publisher' => 'FetchAuthorContentHandler',
            ],
            [
                'pattern' => '/comments/{coordinate}',
                'description' => 'Live comment updates for articles',
                'publisher' => 'FetchCommentsHandler',
            ],
            [
                'pattern' => '/event-fetch/{lookupKey}',
                'description' => 'Async event fetch result notification',
                'publisher' => 'FetchEventFromRelaysHandler',
            ],
            [
                'pattern' => '/curation/{id}/media-sync',
                'description' => 'Curation media sync completion',
                'publisher' => 'FetchMissingCurationMediaHandler',
            ],
            [
                'pattern' => '/chat/{communityId}/group/{slug}',
                'description' => 'Chat group real-time messages',
                'publisher' => 'ChatMessageService',
            ],
            [
                'pattern' => '/test/*',
                'description' => 'Test/diagnostic topics',
                'publisher' => 'TestMercureCommand / MercureAdminService',
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Create a JWT for the subscriptions endpoint.
     */
    private function createSubscriptionJwt(): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'mercure' => [
                'subscribe' => ['*'],
            ],
        ]);

        $base64Header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $base64Payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $this->mercureJwtSecret, true);
        $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 0;
        while ($bytes >= 1024 && $factor < count($units) - 1) {
            $bytes /= 1024;
            $factor++;
        }

        return round($bytes, 2) . ' ' . $units[$factor];
    }
}

