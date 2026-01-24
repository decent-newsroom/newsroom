<?php

declare(strict_types=1);

namespace App\Service\Admin;

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
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $nostrDefaultRelay = null
    ) {
    }

    /**
     * Get relay statistics by actually querying the relay
     */
    public function getStats(): array
    {
        try {
            $relayUrl = $this->nostrDefaultRelay ?? 'ws://strfry:7777';

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
            $relayUrl = $this->nostrDefaultRelay ?? 'ws://strfry:7777';

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
            'relay_url' => $this->nostrDefaultRelay ?? 'Not configured',
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
        $relayUrl = $this->nostrDefaultRelay ?? 'ws://strfry:7777';
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
}

