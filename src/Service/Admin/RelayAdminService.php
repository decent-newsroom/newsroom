<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayRegistry;
use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

/**
 * Service to interact with the strfry relay
 */
class RelayAdminService
{
    private const REQUEST_STREAM = 'relay:requests';
    private const CONTROL_STREAM = 'relay:control';

    /** Fallback when the local relay env var is not configured */
    private const DEFAULT_LOCAL_RELAY = 'ws://strfry:7777';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RelayRegistry $relayRegistry,
        private readonly RelayHealthStore $healthStore,
        private readonly NostrRelayPool $relayPool,
        private readonly \Redis $redis,
    ) {
    }

    /**
     * Resolve the local relay URL from the registry, falling back to the
     * default strfry Docker address when not configured.
     */
    private function getLocalRelayUrl(): string
    {
        return $this->relayRegistry->getLocalRelay() ?? self::DEFAULT_LOCAL_RELAY;
    }

    /**
     * Get relay statistics by actually querying the relay
     */
    public function getStats(): array
    {
        try {
            $relayUrl = $this->getLocalRelayUrl();

            // Test if relay is accessible
            if (!$this->testRelayConnection($relayUrl)) {
                return [
                    'error' => 'Cannot connect to relay at ' . $relayUrl,
                    'total_events' => 0,
                    'relay_accessible' => false
                ];
            }

            // Try to count events by querying with a limit
            $eventCount = $this->estimateEventCount($relayUrl);

            // Format the event count message
            if ($eventCount >= 100) {
                $displayCount = '100+ (many events - use CLI for exact count)';
            } elseif ($eventCount > 0) {
                $displayCount = $eventCount;
            } else {
                $displayCount = 0;
            }

            return [
                'total_events' => $displayCount,
                'relay_accessible' => true,
                'database_size' => '~800 MB (from docker volume)',
                'info' => 'Sample of ' . $eventCount . ' events retrieved. Use CLI for full statistics.'
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get relay stats', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get recent events from relay by actually querying it
     */
    public function getRecentEvents(int $limit = 10): array
    {
        try {
            $relayUrl = $this->getLocalRelayUrl();

            // Create relay connection
            $relay = new Relay($relayUrl);

            // Create subscription
            $subscription = new Subscription();
            $subscriptionId = $subscription->setId();

            // Create filter for recent events (kind 30023 - articles)
            $filter = new Filter();
            $filter->setKinds([30023, 9802]); // Articles, highlights
            $filter->setLimit($limit);

            // Create and send request
            $requestMessage = new RequestMessage($subscriptionId, [$filter]);
            $request = new Request($relay, $requestMessage);

            // Get response with timeout
            $response = $request->send();

            $events = [];
            if (is_array($response) && !empty($response)) {
                foreach ($response as $relayResponse) {
                    if (is_array($relayResponse)) {
                        foreach ($relayResponse as $item) {
                            if (isset($item->type) && $item->type === 'EVENT' && isset($item->event)) {
                                $events[] = (array)$item->event;
                            }
                        }
                    }
                }
            }

            return array_slice($events, 0, $limit);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get recent events', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Estimate event count by querying the relay
     */
    private function estimateEventCount(string $relayUrl): int
    {
        try {
            $relay = new Relay($relayUrl);
            $subscription = new Subscription();
            $subscriptionId = $subscription->setId();

            // Query for a sample to check if relay has events
            $filter = new Filter();
            $filter->setLimit(100);

            $requestMessage = new RequestMessage($subscriptionId, [$filter]);
            $request = new Request($relay, $requestMessage);
            $response = $request->send();

            $count = 0;
            if (is_array($response) && !empty($response)) {
                foreach ($response as $relayResponse) {
                    if (is_array($relayResponse)) {
                        foreach ($relayResponse as $item) {
                            if (isset($item->type) && $item->type === 'EVENT') {
                                $count++;
                            }
                        }
                    }
                }
            }

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get relay container status by checking connectivity
     */
    public function getContainerStatus(): array
    {
        $strfryStatus = $this->checkServiceHealth('strfry', 7777);

        return [
            'strfry' => $strfryStatus,
        ];
    }

    /**
     * Get relay configuration from environment
     */
    public function getConfiguration(): array
    {
        return [
            'relay_url' => $this->getLocalRelayUrl(),
            'relay_internal' => 'ws://strfry:7777',
            'relay_external' => 'ws://localhost:7777',
            'upstreams' => $_ENV['RELAY_UPSTREAMS'] ?? 'Not configured',
            'days_articles' => $_ENV['RELAY_DAYS_ARTICLES'] ?? '7',
            'days_threads' => $_ENV['RELAY_DAYS_THREADS'] ?? '3',
        ];
    }

    /**
     * Test relay connectivity
     */
    public function testConnectivity(): array
    {
        $relayUrl = $this->getLocalRelayUrl();
        $isAccessible = $this->testRelayConnection($relayUrl);

        return [
            'container_running' => $isAccessible,
            'port_accessible' => $isAccessible,
            'relay_url' => $relayUrl,
        ];
    }

    /**
     * Test if we can connect to the relay
     */
    private function testRelayConnection(string $url): bool
    {
        try {
            // Parse URL to get host and port
            $parts = parse_url($url);
            if (!$parts || !isset($parts['host'])) {
                return false;
            }

            $host = $parts['host'];
            $port = $parts['port'] ?? 7777;

            // Try to open a socket connection
            $socket = @fsockopen($host, $port, $errno, $errstr, 2);

            if ($socket) {
                fclose($socket);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->error('Relay connection test failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check service health by testing port connectivity
     */
    private function checkServiceHealth(string $host, int $port): array
    {
        $isRunning = $this->testPortOpen($host, $port);

        return [
            'status' => $isRunning ? 'running' : 'not running',
            'health' => $isRunning ? 'healthy' : 'unhealthy',
            'name' => $host,
            'port' => $port,
            'method' => 'socket_test'
        ];
    }

    /**
     * Test if a port is open
     */
    private function testPortOpen(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            return true;
        }
        return false;
    }

    /**
     * Trigger manual sync - placeholder
     */
    public function triggerSync(): array
    {
        return [
            'success' => false,
            'message' => 'Manual sync trigger not available from web interface. Use CLI: make relay-ingest-now',
        ];
    }

    /**
     * Get recent sync logs - placeholder
     */
    public function getSyncLogs(int $lines = 50): string
    {
        return 'Log viewing not available from web interface. Use CLI: docker compose logs ingest';
    }

    /**
     * Get pool status: all known relays from registry with health data.
     *
     * Returns:
     *   - by_purpose: purpose => [ {url, health_score, healthy, ...health fields} ]
     *   - active_connections: int
     *   - local_relay: string|null
     *   - public_url: string|null
     *   - gateway_enabled: bool
     */
    public function getPoolStatus(): array
    {
        try {
            $all = $this->relayRegistry->getAll(); // purpose => string[]
            $allUrls = $this->relayRegistry->getAllUrls();
            $healthData = $this->healthStore->getHealthForRelays($allUrls);

            $byPurpose = [];
            foreach ($all as $purpose => $urls) {
                $byPurpose[$purpose] = [];
                foreach ($urls as $url) {
                    // PROJECT is a public alias for LOCAL — it is never directly
                    // connected to. Pull health data from the LOCAL (internal) URL
                    // so the dashboard shows real activity, not empty zeros.
                    $healthUrl = $this->relayRegistry->resolveToLocalUrl($url);
                    $h = $healthData[$healthUrl] ?? $this->healthStore->getHealth($healthUrl);

                    $byPurpose[$purpose][] = [
                        'url' => $url,
                        'is_project_alias' => $this->relayRegistry->isProjectRelay($url),
                        'health_score' => round($this->healthStore->getHealthScore($healthUrl), 2),
                        'healthy' => $this->healthStore->isHealthy($healthUrl),
                        'consecutive_failures' => $h['consecutive_failures'],
                        'avg_latency_ms' => $h['avg_latency_ms'],
                        'last_success' => $h['last_success'],
                        'last_failure' => $h['last_failure'],
                        'auth_required' => $h['auth_required'],
                        'auth_status' => $h['auth_status'],
                        'last_event_received' => $h['last_event_received'],
                        'heartbeats' => $h['heartbeats'],
                    ];
                }
            }

            $poolStats = $this->relayPool->getStats();

            return [
                'by_purpose' => $byPurpose,
                'active_connections' => $poolStats['active_connections'] ?? 0,
                'local_relay' => $this->relayPool->getLocalRelay(),
                'public_url' => $this->relayRegistry->getPublicUrl(),
                'gateway_enabled' => $this->relayPool->isGatewayEnabled(),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('RelayAdminService: failed to build pool status', ['error' => $e->getMessage()]);
            return [
                'by_purpose' => [],
                'active_connections' => 0,
                'local_relay' => null,
                'public_url' => null,
                'gateway_enabled' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Collect subscription worker heartbeats from RelayHealthStore.
     * Returns worker_name => ['timestamp' => int, 'age_seconds' => int]
     */
    public function getWorkerHeartbeats(): array
    {
        try {
            $allUrls = $this->relayRegistry->getAllUrls();
            $merged = [];

            foreach ($allUrls as $url) {
                $h = $this->healthStore->getHealth($url);
                foreach ($h['heartbeats'] as $worker => $ts) {
                    // Keep the most recent heartbeat if the same worker appears for multiple relays
                    if (!isset($merged[$worker]) || $ts > $merged[$worker]['timestamp']) {
                        $merged[$worker] = [
                            'timestamp' => $ts,
                            'age_seconds' => time() - $ts,
                        ];
                    }
                }
            }

            // Sort by worker name
            ksort($merged);
            return $merged;
        } catch (\Throwable $e) {
            $this->logger->warning('RelayAdminService: failed to collect heartbeats', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get full gateway status: all known relays, gateway heartbeat, Redis streams.
     *
     * Mirrors the data from RelayGatewayStatusCommand but returns it as an array
     * for the admin web UI.
     */
    public function getGatewayStatus(): array
    {
        $result = [
            'relays' => [],
            'gateway' => $this->getGatewayHeartbeat(),
            'streams' => $this->getStreamStatus(),
        ];

        try {
            // Get ALL relays from Redis health store (not just configured)
            $allUrls = $this->healthStore->getAllKnownRelayUrls();
            $configuredUrls = $this->relayRegistry->getAllUrls();
            $mutedUrls = $this->healthStore->getMutedRelays();

            foreach ($allUrls as $url) {
                $health = $this->healthStore->getHealth($url);
                $result['relays'][] = [
                    'url' => $url,
                    'is_configured' => in_array($url, $configuredUrls, true),
                    'is_local' => $this->isLocalRelay($url),
                    'is_muted' => in_array(rtrim(trim($url), '/'), $mutedUrls, true),
                    'health_score' => round($this->healthStore->getHealthScore($url), 2),
                    'healthy' => $this->healthStore->isHealthy($url),
                    'consecutive_failures' => $health['consecutive_failures'],
                    'avg_latency_ms' => $health['avg_latency_ms'],
                    'last_success' => $health['last_success'],
                    'last_failure' => $health['last_failure'],
                    'auth_required' => $health['auth_required'],
                    'auth_status' => $health['auth_status'],
                    'last_event_received' => $health['last_event_received'],
                ];
            }

            // Also include muted relays that no longer have health data
            foreach ($mutedUrls as $mutedUrl) {
                $alreadyIncluded = array_filter($result['relays'], fn($r) => rtrim(trim($r['url']), '/') === $mutedUrl);
                if (empty($alreadyIncluded)) {
                    $result['relays'][] = [
                        'url' => $mutedUrl,
                        'is_configured' => in_array($mutedUrl, $configuredUrls, true),
                        'is_local' => $this->isLocalRelay($mutedUrl),
                        'is_muted' => true,
                        'health_score' => 0,
                        'healthy' => false,
                        'consecutive_failures' => 0,
                        'avg_latency_ms' => null,
                        'last_success' => null,
                        'last_failure' => null,
                        'auth_required' => false,
                        'auth_status' => 'none',
                        'last_event_received' => null,
                    ];
                }
            }

            // Sort: configured first, then by health score desc
            usort($result['relays'], function ($a, $b) {
                if ($a['is_configured'] !== $b['is_configured']) {
                    return $b['is_configured'] <=> $a['is_configured'];
                }
                return $b['health_score'] <=> $a['health_score'];
            });
        } catch (\Throwable $e) {
            $this->logger->warning('RelayAdminService: failed to build gateway status', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Get gateway process heartbeat information.
     */
    private function getGatewayHeartbeat(): array
    {
        try {
            $heartbeat = $this->redis->get('relay_gateway:heartbeat');
            if ($heartbeat) {
                $ago = time() - (int) $heartbeat;
                return [
                    'alive' => $ago < 120,
                    'age_seconds' => $ago,
                    'timestamp' => (int) $heartbeat,
                ];
            }
        } catch (\Throwable) {}

        return [
            'alive' => false,
            'age_seconds' => null,
            'timestamp' => null,
        ];
    }

    /**
     * Get Redis stream status for relay:requests and relay:control.
     */
    private function getStreamStatus(): array
    {
        $cursorKeys = [
            self::REQUEST_STREAM => 'relay_gateway:cursor:requests',
            self::CONTROL_STREAM => 'relay_gateway:cursor:control',
        ];

        $streams = [];
        foreach ([self::REQUEST_STREAM, self::CONTROL_STREAM] as $stream) {
            try {
                $len = $this->redis->xLen($stream);
                $info = $this->redis->xInfo('STREAM', $stream);
                $lastId = $info['last-generated-id'] ?? 'n/a';

                $cursor = $this->redis->get($cursorKeys[$stream]);
                $pending = null;
                $status = '— (no cursor)';

                if ($cursor) {
                    try {
                        $parts = explode('-', $cursor, 2);
                        $nextId = count($parts) === 2 ? $parts[0] . '-' . ((int) $parts[1] + 1) : $cursor;
                        $afterCursor = $this->redis->xRange($stream, $nextId, '+', 100);
                        $pending = $afterCursor ? count($afterCursor) : 0;
                        $status = $pending === 0 ? '✓ caught up' : sprintf('%d pending', $pending);
                    } catch (\Throwable) {
                        $status = '— (cursor error)';
                    }
                }

                $streams[] = [
                    'name' => $stream,
                    'length' => $len,
                    'last_id' => $lastId,
                    'pending' => $pending,
                    'status' => $status,
                ];
            } catch (\Throwable) {
                $streams[] = [
                    'name' => $stream,
                    'length' => null,
                    'last_id' => null,
                    'pending' => null,
                    'status' => '— (unavailable)',
                ];
            }
        }

        return $streams;
    }

    /**
     * Mute a relay URL. The local relay cannot be muted.
     */
    public function muteRelay(string $url): bool
    {
        if ($this->isLocalRelay($url)) {
            return false;
        }

        $this->healthStore->muteRelay($url);
        return true;
    }

    /**
     * Unmute a relay URL.
     */
    public function unmuteRelay(string $url): void
    {
        $this->healthStore->unmuteRelay($url);
    }

    /**
     * Reset health data for a relay (clear consecutive failures).
     */
    public function resetRelayHealth(string $url): void
    {
        $this->healthStore->recordSuccess($url);
    }

    /**
     * Check whether a URL is the local (strfry) relay.
     */
    public function isLocalRelay(string $url): bool
    {
        $local = $this->relayRegistry->getLocalRelay();
        if ($local === null) {
            return false;
        }
        return RelayUrlNormalizer::equals($url, $local);
    }
}
