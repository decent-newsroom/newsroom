<?php

namespace App\Command;

use App\Repository\EventRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Subscribes to the local strfry relay for kind:777 (NIP-A7) spell events
 * and projects them into the generic `event` table so they can be listed,
 * previewed and executed via the ExpressionBundle.
 *
 * Spells are regular (non-replaceable) events addressed by event id.
 */
#[AsCommand(
    name: 'spells:subscribe-local-relay',
    description: 'Subscribe to local relay for spell events (kind 777) and persist them to the database in real-time'
)]
class SubscribeLocalSpellsCommand extends Command
{
    private const KIND_SPELL = 777;

    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly RelayRegistry $relayRegistry,
        private readonly GenericEventProjector $projector,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Subscribes to the local Nostr relay for spell events (kind 777, NIP-A7) ' .
            'and persists them to the database. Runs as a long-lived daemon process.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $localRelay = $this->relayRegistry->getLocalRelay();
        if (!$localRelay) {
            $io->error('Local relay not configured. Please set NOSTR_DEFAULT_RELAY environment variable.');
            return Command::FAILURE;
        }

        $io->title('Spell Hydration Worker');
        $io->info(sprintf('Subscribing to local relay: %s', $localRelay));
        $io->info('Listening for spell events (kind 777)...');
        $io->newLine();

        try {
            // Resume from last known spell so we don't replay history every restart.
            $since = $this->eventRepository->findLatestCreatedAtByKinds([self::KIND_SPELL]);
            if ($since !== null) {
                $io->info(sprintf(
                    'Resuming from last known spell: %s (timestamp %d)',
                    date('Y-m-d H:i:s', $since),
                    $since
                ));
            } else {
                $io->info('No existing spells — fetching full history from relay');
            }

            $this->relayPool->subscribeLocalGenericEvents(
                [self::KIND_SPELL],
                function (object $event, string $relayUrl) use ($io) {
                    $timestamp = date('Y-m-d H:i:s');
                    $eventId = substr($event->id ?? 'unknown', 0, 16) . '...';
                    $pubkey = substr($event->pubkey ?? 'unknown', 0, 16) . '...';

                    $io->writeln(sprintf(
                        '[%s] <fg=green>Spell received:</> %s (pubkey: %s)',
                        $timestamp,
                        $eventId,
                        $pubkey
                    ));

                    try {
                        $this->projector->projectEventFromNostrEvent($event, $relayUrl);
                        $io->writeln(sprintf(
                            '[%s] <fg=green>✓</> Spell saved to database',
                            date('Y-m-d H:i:s')
                        ));
                    } catch (\InvalidArgumentException $e) {
                        $io->writeln(sprintf(
                            '[%s] <fg=yellow>⚠</> Skipped invalid spell: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    } catch (\Throwable $e) {
                        $io->writeln(sprintf(
                            '[%s] <fg=red>✗</> Error saving spell: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    }
                    $io->newLine();
                },
                $since
            );

            // @phpstan-ignore-next-line
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Subscription failed: ' . $e->getMessage());
            $this->logger->error('Spell hydration worker failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}

