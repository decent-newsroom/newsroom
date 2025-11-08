#!/usr/bin/env php
<?php
/**
 * Smoke test for the local relay
 * Tests that the relay is up and can serve basic queries
 */

declare(strict_types=1);

// Bootstrap Symfony autoloader if available, or try to use vendor autoload directly
$possibleAutoloaders = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($possibleAutoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    fwrite(STDERR, "ERROR: Could not find autoloader. Run 'composer install' first.\n");
    exit(1);
}

use swentel\nostr\Relay\Relay;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Filter;
use WebSocket\Message\Text;
use WebSocket\Exception\TimeoutException;

// Get relay URL from environment or use default
$relayUrl = getenv('NOSTR_DEFAULT_RELAY') ?: 'ws://localhost:7777';

echo "Testing relay: {$relayUrl}\n";
echo str_repeat('-', 60) . "\n";

try {
    // Test 1: Basic connection
    echo "Test 1: Connecting to relay...\n";
    $relay = new Relay($relayUrl);
    $relay->connect();
    echo "✓ Connected successfully\n\n";

    // Test 2: Query for long-form articles (kind 30023)
    echo "Test 2: Querying for kind:30023 events (limit 1)...\n";

    $filter = new Filter();
    $filter->setKinds([30023]);
    $filter->setLimit(1);

    $subscriptionId = 'test-' . bin2hex(random_bytes(8));
    $requestMessage = new RequestMessage($subscriptionId, [$filter]);

    $client = $relay->getClient();
    $client->setTimeout(10);
    $client->text($requestMessage->generate());

    $foundEvent = false;
    $eventCount = 0;
    $startTime = time();
    $timeout = 10;

    while ((time() - $startTime) < $timeout) {
        try {
            $response = $client->receive();

            if (!$response instanceof Text) {
                continue;
            }

            $content = $response->getContent();
            $decoded = json_decode($content, true);

            if (!is_array($decoded) || count($decoded) < 2) {
                continue;
            }

            $messageType = $decoded[0] ?? '';

            if ($messageType === 'EVENT') {
                $eventCount++;
                $event = $decoded[2] ?? [];
                $eventId = $event['id'] ?? 'unknown';
                $eventKind = $event['kind'] ?? 'unknown';

                echo "✓ Received EVENT: id={$eventId}, kind={$eventKind}\n";
                $foundEvent = true;

                // Send CLOSE
                $client->text(json_encode(['CLOSE', $subscriptionId]));
                break;
            } elseif ($messageType === 'EOSE') {
                echo "  Received EOSE (End of Stored Events)\n";
                // Send CLOSE
                $client->text(json_encode(['CLOSE', $subscriptionId]));
                break;
            } elseif ($messageType === 'NOTICE' || $messageType === 'CLOSED') {
                echo "  Received {$messageType}: " . ($decoded[1] ?? '') . "\n";
                break;
            }
        } catch (TimeoutException $e) {
            echo "  Timeout waiting for response\n";
            break;
        }
    }

    if (!$foundEvent && $eventCount === 0) {
        echo "⚠ No events found (relay might be empty - try running 'make relay-prime' first)\n\n";
    } else {
        echo "\n";
    }

    // Test 3: Verify write rejection
    echo "Test 3: Testing write policy (should reject)...\n";
    // We'll just document this - actual test would require creating a signed event
    echo "⚠ Write rejection test not implemented (requires event signing)\n";
    echo "  Manual test: Try publishing an event - should receive rejection message\n\n";

    $relay->disconnect();

    echo str_repeat('-', 60) . "\n";
    echo "✓ Smoke test completed successfully\n";

    exit(0);

} catch (\Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

