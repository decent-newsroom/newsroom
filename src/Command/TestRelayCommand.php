<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\NostrSigner;
use App\Service\Nostr\RelayEndpoint;
use App\Service\Nostr\RelayQueryRequest;
use App\Service\Nostr\RelaySet;
use Innis\Nostr\Core\Domain\Entity\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nostr:test-relay',
    description: 'Test basic Nostr REQ and EVENT flow with a relay',
)]
final class TestRelayCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NostrRelayPool $relayPool,
        private readonly NostrSigner $signer,
        private readonly ?string $nostrDefaultRelay = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('relay-url', InputArgument::OPTIONAL, 'WebSocket relay URL', null)
            ->addOption('kinds', 'k', InputOption::VALUE_OPTIONAL, 'Comma-separated kinds to filter', '1')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of events', '5')
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Timeout in seconds', '10')
            ->addOption('send-event', 's', InputOption::VALUE_NONE, 'Send a test event instead of requesting')
            ->addOption('event-json', 'j', InputOption::VALUE_OPTIONAL, 'JSON string or file path of pre-signed event', null)
            ->addOption('content', 'c', InputOption::VALUE_OPTIONAL, 'Content for the event to send', 'Test event from nostr:test-relay')
            ->addOption('event-kind', null, InputOption::VALUE_OPTIONAL, 'Kind for the event to send', '1')
            ->addOption('private-key', 'p', InputOption::VALUE_OPTIONAL, 'Private key (hex) for signing event', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $relayUrl = $input->getArgument('relay-url') ?? $this->nostrDefaultRelay ?? 'ws://localhost:7777';
        $timeout = (int) $input->getOption('timeout');

        try {
            $eventJson = $input->getOption('event-json');
            if ($eventJson !== null) {
                return $this->sendPreSignedEvent($io, $relayUrl, $eventJson, $timeout);
            }
            if ($input->getOption('send-event')) {
                return $this->sendEvent(
                    $io,
                    $relayUrl,
                    (string) $input->getOption('content'),
                    (int) $input->getOption('event-kind'),
                    $input->getOption('private-key'),
                    $timeout,
                );
            }

            return $this->requestEvents(
                $io,
                $relayUrl,
                array_map('intval', explode(',', (string) $input->getOption('kinds'))),
                (int) $input->getOption('limit'),
                $timeout,
            );
        } catch (\Throwable $e) {
            $io->error('Test failed: '.$e->getMessage());
            $this->logger->error('Relay test failed', ['relay' => $relayUrl, 'error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }

    /** @param list<int> $kinds */
    private function requestEvents(SymfonyStyle $io, string $relayUrl, array $kinds, int $limit, int $timeout): int
    {
        $request = new RelayQueryRequest(
            new RelaySet([new RelayEndpoint($relayUrl)]),
            [['kinds' => $kinds, 'limit' => $limit]],
        );
        $request->setTimeout($timeout);
        $results = $this->relayPool->executeRequest($request);
        $events = [];
        foreach ($results as $result) {
            foreach ($result->events as $event) {
                $events[] = $event;
                $io->writeln(sprintf(
                    '<info>Event #%d:</info> Kind: %d, ID: %s, Created: %s',
                    count($events),
                    $event->getKind(),
                    substr($event->getId()->toHex(), 0, 16).'...',
                    date('Y-m-d H:i:s', $event->getCreatedAt()),
                ));
                $io->writeln('  Content: '.mb_substr($event->getContent(), 0, 80));
            }
            if ($result->eose) {
                $io->success('EOSE (End of Stored Events) received');
            } elseif ($result->error !== null) {
                $io->warning($result->error);
            }
        }

        $io->text(sprintf('Received %d event(s) from %s.', count($events), $relayUrl));

        return Command::SUCCESS;
    }

    private function sendEvent(
        SymfonyStyle $io,
        string $relayUrl,
        string $content,
        int $eventKind,
        ?string $privateKey,
        int $timeout,
    ): int {
        $keyService = new NostrKeyService();
        $privateKey ??= $keyService->generatePrivateKey();
        $event = $this->signer->signWithPrivateKey($eventKind, [], $content, $privateKey);
        $result = array_values($this->relayPool->publish($event, [$relayUrl], $event->getPubkey()->toHex(), $timeout))[0] ?? null;

        $io->table(
            ['Field', 'Value'],
            [
                ['Relay URL', $relayUrl],
                ['Event ID', $event->getId()->toHex()],
                ['Public Key', $event->getPubkey()->toHex()],
                ['Accepted', ($result['ok'] ?? false) ? 'Yes' : 'No'],
                ['Message', $result['message'] ?? ''],
            ],
        );

        return ($result['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }

    private function sendPreSignedEvent(SymfonyStyle $io, string $relayUrl, string $eventJson, int $timeout): int
    {
        $json = file_exists($eventJson) ? file_get_contents($eventJson) : $eventJson;
        $eventData = json_decode($json ?: '', true);
        if (!is_array($eventData)) {
            $io->error('Invalid JSON provided');

            return Command::FAILURE;
        }

        foreach (['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'] as $field) {
            if (!array_key_exists($field, $eventData)) {
                $io->error("Missing required field: $field");

                return Command::FAILURE;
            }
        }

        $event = Event::fromArray($eventData);
        $result = array_values($this->relayPool->publish($event, [$relayUrl], $event->getPubkey()->toHex(), $timeout))[0] ?? null;
        $accepted = (bool) ($result['ok'] ?? false);
        if ($accepted) {
            $io->success('Event accepted by relay.');
        } else {
            $io->error($result['message'] ?? 'Event rejected by relay.');
        }

        return $accepted ? Command::SUCCESS : Command::FAILURE;
    }
}
