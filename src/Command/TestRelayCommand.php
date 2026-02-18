<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use swentel\nostr\Event\Event;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Key\Key;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Subscription\Subscription;
use WebSocket\Client;

#[AsCommand(
    name: 'nostr:test-relay',
    description: 'Test basic Nostr REQ flow communication with a relay (WebSocket)',
)]
class TestRelayCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $nostrDefaultRelay = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('relay-url', InputArgument::OPTIONAL, 'WebSocket relay URL (e.g., ws://localhost:7777)', null)
            ->addOption('kinds', 'k', InputOption::VALUE_OPTIONAL, 'Comma-separated kinds to filter (e.g., 1,30023)', '1')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of events', '5')
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Timeout in seconds', '10')
            ->addOption('send-event', 's', InputOption::VALUE_NONE, 'Send a test event instead of requesting')
            ->addOption('event-json', 'j', InputOption::VALUE_OPTIONAL, 'JSON string or file path of pre-signed event to send', null)
            ->addOption('content', 'c', InputOption::VALUE_OPTIONAL, 'Content for the event to send', 'Test event from nostr:test-relay')
            ->addOption('event-kind', null, InputOption::VALUE_OPTIONAL, 'Kind for the event to send', '1')
            ->addOption('private-key', 'p', InputOption::VALUE_OPTIONAL, 'Private key (hex) for signing event', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Determine relay URL
        $relayUrl = $input->getArgument('relay-url') ?? $this->nostrDefaultRelay ?? 'ws://localhost:7777';

        // Parse options
        $sendEvent = $input->getOption('send-event');
        $eventJson = $input->getOption('event-json');
        $kindsInput = $input->getOption('kinds');
        $kinds = array_map('intval', explode(',', $kindsInput));
        $limit = (int) $input->getOption('limit');
        $timeout = (int) $input->getOption('timeout');
        $content = $input->getOption('content');
        $eventKind = (int) $input->getOption('event-kind');
        $privateKey = $input->getOption('private-key');

        $io->title($sendEvent || $eventJson ? 'Nostr Relay Test - Send Event' : 'Nostr Relay Test - REQ Flow');

        if ($eventJson) {
            return $this->sendPreSignedEvent($io, $relayUrl, $eventJson, $timeout);
        } elseif ($sendEvent) {
            return $this->sendEventFlow($io, $relayUrl, $content, $eventKind, $privateKey, $timeout);
        } else {
            return $this->requestFlow($io, $relayUrl, $kinds, $limit, $timeout);
        }
    }

    private function requestFlow(SymfonyStyle $io, string $relayUrl, array $kinds, int $limit, int $timeout): int
    {
        $io->section('Configuration');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Relay URL', $relayUrl],
                ['Kinds', implode(', ', $kinds)],
                ['Limit', $limit],
                ['Timeout', $timeout . 's'],
            ]
        );

        try {
            $io->section('Connecting to Relay');
            $io->text("Attempting to connect to: $relayUrl");

            $relay = new Relay($relayUrl);
            $relay->connect();

            if (!$relay->isConnected()) {
                $io->error('Failed to connect to relay');
                return Command::FAILURE;
            }

            $io->success('Connected to relay!');

            // Get the WebSocket client
            $client = $relay->getClient();
            $client->setTimeout($timeout);

            // Create subscription
            $subscription = new Subscription();
            $subscriptionId = $subscription->setId();

            // Create filter
            $filter = new Filter();
            $filter->setKinds($kinds);
            $filter->setLimit($limit);

            // Build REQ message
            $requestMessage = new RequestMessage($subscriptionId, [$filter]);
            $payload = $requestMessage->generate();

            $io->section('Sending REQ');
            $io->text("Subscription ID: $subscriptionId");
            $io->text("Payload: " . $payload);

            // Send the request
            $client->text($payload);
            $io->success('REQ sent!');

            // Receive responses
            $io->section('Receiving Events');
            $io->text('Waiting for relay responses...');
            $io->newLine();

            $eventCount = 0;
            $eoseReceived = false;
            $startTime = time();

            while (true) {
                // Check timeout
                if ((time() - $startTime) > $timeout) {
                    $io->warning('Timeout reached');
                    break;
                }

                try {
                    $resp = $client->receive();

                    // Handle PING
                    if ($resp instanceof \WebSocket\Message\Ping) {
                        $client->pong();
                        continue;
                    }

                    // Only process text messages
                    if (!($resp instanceof \WebSocket\Message\Text)) {
                        continue;
                    }

                    $content = $resp->getContent();
                    $decoded = json_decode($content, true);

                    if (!$decoded || !is_array($decoded)) {
                        continue;
                    }

                    $messageType = $decoded[0] ?? null;

                    // Handle EVENT message
                    if ($messageType === 'EVENT') {
                        $eventCount++;
                        $eventData = $decoded[2] ?? [];

                        $io->writeln(sprintf(
                            '<info>Event #%d:</info> Kind: %s, ID: %s, Created: %s',
                            $eventCount,
                            $eventData['kind'] ?? 'unknown',
                            substr($eventData['id'] ?? '', 0, 16) . '...',
                            isset($eventData['created_at']) ? date('Y-m-d H:i:s', $eventData['created_at']) : 'unknown'
                        ));

                        if (isset($eventData['content'])) {
                            $preview = mb_substr($eventData['content'], 0, 80);
                            $io->writeln("  Content: " . $preview . (strlen($eventData['content']) > 80 ? '...' : ''));
                        }
                        $io->newLine();
                    }
                    // Handle EOSE message
                    elseif ($messageType === 'EOSE') {
                        $eoseReceived = true;
                        $io->success('EOSE (End of Stored Events) received');
                        break;
                    }
                    // Handle NOTICE message
                    elseif ($messageType === 'NOTICE') {
                        $io->warning('NOTICE: ' . ($decoded[1] ?? 'unknown'));
                    }
                    // Handle OK message
                    elseif ($messageType === 'OK') {
                        $io->info('OK: ' . json_encode($decoded));
                    }
                    // Unknown message type
                    else {
                        $io->text('Other: ' . $content);
                    }

                } catch (\WebSocket\TimeoutException $e) {
                    $io->warning('Receive timeout - no more messages');
                    break;
                } catch (\Exception $e) {
                    $io->error('Error receiving message: ' . $e->getMessage());
                    break;
                }
            }

            // Close connection
            $relay->disconnect();

            $io->section('Summary');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Events Received', $eventCount],
                    ['EOSE Received', $eoseReceived ? 'Yes' : 'No'],
                    ['Connection', 'Closed'],
                ]
            );

            if ($eventCount > 0 || $eoseReceived) {
                $io->success('Relay test completed successfully!');
                return Command::SUCCESS;
            } else {
                $io->warning('No events received from relay');
                return Command::SUCCESS;
            }

        } catch (\Exception $e) {
            $io->error('Test failed: ' . $e->getMessage());
            $this->logger->error('Relay test failed', [
                'relay' => $relayUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    private function sendEventFlow(SymfonyStyle $io, string $relayUrl, string $content, int $eventKind, ?string $privateKey, int $timeout): int
    {
        try {
            // Generate or use provided key
            $key = new Key();
            if ($privateKey) {
                $privKey = $privateKey;
                $io->text('Using provided private key');
            } else {
                $privKey = $key->generatePrivateKey();
                $io->text('Generated new private key: ' . $privKey);
            }

            $pubKey = $key->getPublicKey($privKey);

            $io->section('Configuration');
            $io->table(
                ['Setting', 'Value'],
                [
                    ['Relay URL', $relayUrl],
                    ['Event Kind', $eventKind],
                    ['Content', mb_substr($content, 0, 60) . (strlen($content) > 60 ? '...' : '')],
                    ['Public Key', substr($pubKey, 0, 16) . '...'],
                    ['Timeout', $timeout . 's'],
                ]
            );

            $io->section('Connecting to Relay');
            $io->text("Attempting to connect to: $relayUrl");

            $relay = new Relay($relayUrl);
            $relay->connect();

            if (!$relay->isConnected()) {
                $io->error('Failed to connect to relay');
                return Command::FAILURE;
            }

            $io->success('Connected to relay!');

            // Get the WebSocket client
            $client = $relay->getClient();
            $client->setTimeout($timeout);

            // Create event
            $event = new Event();
            $event->setKind($eventKind);
            $event->setContent($content);
            $event->setTags([]);

            // Sign the event using Sign class
            $signer = new Sign();
            $signer->signEvent($event, $privKey);

            // Create event message
            $eventMessage = new EventMessage($event);
            $payload = $eventMessage->generate();

            $io->section('Sending Event');
            $io->text("Event ID: " . $event->getId());
            $io->text("Payload: " . mb_substr($payload, 0, 120) . '...');

            // Send the event
            $client->text($payload);
            $io->success('Event sent!');

            // Wait for OK response
            $io->section('Waiting for Response');
            $io->text('Waiting for relay OK/NOTICE...');
            $io->newLine();

            $startTime = time();
            $gotResponse = false;

            while (true) {
                // Check timeout
                if ((time() - $startTime) > $timeout) {
                    $io->warning('Timeout reached');
                    break;
                }

                try {
                    $resp = $client->receive();

                    // Handle PING
                    if ($resp instanceof \WebSocket\Message\Ping) {
                        $client->pong();
                        continue;
                    }

                    // Only process text messages
                    if (!($resp instanceof \WebSocket\Message\Text)) {
                        continue;
                    }

                    $responseContent = $resp->getContent();
                    $decoded = json_decode($responseContent, true);

                    if (!$decoded || !is_array($decoded)) {
                        continue;
                    }

                    $messageType = $decoded[0] ?? null;

                    // Handle OK message
                    if ($messageType === 'OK') {
                        $gotResponse = true;
                        $eventId = $decoded[1] ?? 'unknown';
                        $accepted = $decoded[2] ?? false;
                        $message = $decoded[3] ?? '';

                        if ($accepted) {
                            $io->success(sprintf('Event accepted! ID: %s', substr($eventId, 0, 16) . '...'));
                            if ($message) {
                                $io->text('Message: ' . $message);
                            }
                        } else {
                            $io->error(sprintf('Event rejected! ID: %s', substr($eventId, 0, 16) . '...'));
                            $io->text('Reason: ' . $message);
                        }
                        break;
                    }
                    // Handle NOTICE message
                    elseif ($messageType === 'NOTICE') {
                        $gotResponse = true;
                        $io->warning('NOTICE: ' . ($decoded[1] ?? 'unknown'));
                        break;
                    }
                    // Other messages
                    else {
                        $io->text('Received: ' . $responseContent);
                    }

                } catch (\WebSocket\TimeoutException $e) {
                    $io->warning('Receive timeout - no response from relay');
                    break;
                } catch (\Exception $e) {
                    $io->error('Error receiving message: ' . $e->getMessage());
                    break;
                }
            }

            // Close connection
            $relay->disconnect();

            $io->section('Summary');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Event ID', substr($event->getId(), 0, 32) . '...'],
                    ['Public Key', substr($pubKey, 0, 32) . '...'],
                    ['Response Received', $gotResponse ? 'Yes' : 'No'],
                    ['Connection', 'Closed'],
                ]
            );

            if ($gotResponse) {
                $io->success('Event send test completed!');
                return Command::SUCCESS;
            } else {
                $io->warning('No response received from relay (event may still have been accepted)');
                return Command::SUCCESS;
            }

        } catch (\Exception $e) {
            $io->error('Test failed: ' . $e->getMessage());
            $this->logger->error('Relay event send test failed', [
                'relay' => $relayUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    private function sendPreSignedEvent(SymfonyStyle $io, string $relayUrl, string $eventJson, int $timeout): int
    {
        try {
            // Parse event JSON - could be a file path or JSON string
            if (file_exists($eventJson)) {
                $io->text('Reading event from file: ' . $eventJson);
                $eventData = json_decode(file_get_contents($eventJson), true);
            } else {
                $io->text('Parsing event from JSON string');
                $eventData = json_decode($eventJson, true);
            }

            if (!$eventData) {
                $io->error('Invalid JSON provided');
                return Command::FAILURE;
            }

            // Validate required fields
            $requiredFields = ['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'];
            foreach ($requiredFields as $field) {
                if (!isset($eventData[$field])) {
                    $io->error("Missing required field: $field");
                    return Command::FAILURE;
                }
            }

            $io->section('Event Information');
            $io->table(
                ['Field', 'Value'],
                [
                    ['Event ID', substr($eventData['id'], 0, 32) . '...'],
                    ['Public Key', substr($eventData['pubkey'], 0, 32) . '...'],
                    ['Kind', $eventData['kind']],
                    ['Created At', date('Y-m-d H:i:s', $eventData['created_at'])],
                    ['Content Length', strlen($eventData['content']) . ' bytes'],
                    ['Tags', count($eventData['tags'])],
                ]
            );

            // Show some tags
            if (!empty($eventData['tags'])) {
                $io->section('Tags (first 5)');
                $tagRows = [];
                $tagsToShow = array_slice($eventData['tags'], 0, 5);
                foreach ($tagsToShow as $tag) {
                    if (is_array($tag) && count($tag) > 0) {
                        $tagRows[] = [
                            $tag[0] ?? '',
                            isset($tag[1]) ? (strlen($tag[1]) > 50 ? substr($tag[1], 0, 50) . '...' : $tag[1]) : ''
                        ];
                    }
                }
                if (!empty($tagRows)) {
                    $io->table(['Tag Type', 'Value'], $tagRows);
                }
            }

            $io->section('Connecting to Relay');
            $io->text("Attempting to connect to: $relayUrl");

            $relay = new Relay($relayUrl);
            $relay->connect();

            if (!$relay->isConnected()) {
                $io->error('Failed to connect to relay');
                return Command::FAILURE;
            }

            $io->success('Connected to relay!');

            // Get the WebSocket client
            $client = $relay->getClient();
            $client->setTimeout($timeout);

            // Build EVENT message manually: ["EVENT", <event JSON object>]
            $payload = json_encode(['EVENT', $eventData]);

            $io->section('Sending Event');
            $io->text("Payload length: " . strlen($payload) . ' bytes');

            // Send the event
            $client->text($payload);
            $io->success('Event sent!');

            // Wait for OK response
            $io->section('Waiting for Response');
            $io->text('Waiting for relay OK/NOTICE...');
            $io->newLine();

            $startTime = time();
            $gotResponse = false;
            $accepted = false;
            $responseMessage = '';

            while (true) {
                // Check timeout
                if ((time() - $startTime) > $timeout) {
                    $io->warning('Timeout reached');
                    break;
                }

                try {
                    $resp = $client->receive();

                    // Handle PING
                    if ($resp instanceof \WebSocket\Message\Ping) {
                        $client->pong();
                        continue;
                    }

                    // Only process text messages
                    if (!($resp instanceof \WebSocket\Message\Text)) {
                        continue;
                    }

                    $responseContent = $resp->getContent();
                    $decoded = json_decode($responseContent, true);

                    if (!$decoded || !is_array($decoded)) {
                        continue;
                    }

                    $messageType = $decoded[0] ?? null;

                    // Handle OK message
                    if ($messageType === 'OK') {
                        $gotResponse = true;
                        $eventId = $decoded[1] ?? 'unknown';
                        $accepted = $decoded[2] ?? false;
                        $responseMessage = $decoded[3] ?? '';

                        if ($accepted) {
                            $io->success(sprintf('✓ Event accepted by relay!'));
                            $io->text('Event ID: ' . substr($eventId, 0, 16) . '...');
                            if ($responseMessage) {
                                $io->text('Message: ' . $responseMessage);
                            }
                        } else {
                            $io->error(sprintf('✗ Event rejected by relay!'));
                            $io->text('Event ID: ' . substr($eventId, 0, 16) . '...');
                            $io->text('Reason: ' . $responseMessage);
                        }
                        break;
                    }
                    // Handle NOTICE message
                    elseif ($messageType === 'NOTICE') {
                        $gotResponse = true;
                        $io->warning('NOTICE: ' . ($decoded[1] ?? 'unknown'));
                        break;
                    }
                    // Other messages
                    else {
                        $io->text('Received: ' . $responseContent);
                    }

                } catch (\WebSocket\TimeoutException $e) {
                    $io->warning('Receive timeout - no response from relay');
                    break;
                } catch (\Exception $e) {
                    $io->error('Error receiving message: ' . $e->getMessage());
                    break;
                }
            }

            // Close connection
            $relay->disconnect();

            $io->section('Summary');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Event ID', substr($eventData['id'], 0, 32) . '...'],
                    ['Public Key', substr($eventData['pubkey'], 0, 32) . '...'],
                    ['Response Received', $gotResponse ? 'Yes' : 'No'],
                    ['Accepted', $accepted ? 'Yes' : 'No'],
                    ['Connection', 'Closed'],
                ]
            );

            if ($gotResponse && $accepted) {
                $io->success('Event successfully published to relay!');
                return Command::SUCCESS;
            } elseif ($gotResponse && !$accepted) {
                $io->error('Event was rejected by relay');
                return Command::FAILURE;
            } else {
                $io->warning('No response received from relay (event may still have been accepted)');
                return Command::SUCCESS;
            }

        } catch (\Exception $e) {
            $io->error('Test failed: ' . $e->getMessage());
            $this->logger->error('Relay event send test failed', [
                'relay' => $relayUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}









