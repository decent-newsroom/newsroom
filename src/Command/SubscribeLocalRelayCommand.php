<?php

namespace App\Command;

use App\Service\ArticleEventProjector;
use App\Service\NostrRelayPool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'articles:subscribe-local-relay',
    description: 'Subscribe to local relay for new article events and save them to the database in real-time'
)]
class SubscribeLocalRelayCommand extends Command
{
    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly ArticleEventProjector $projector,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'This command subscribes to the local Nostr relay for article events (kind 30023) ' .
            'and automatically persists them to the database. It runs as a long-lived daemon process.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $localRelay = $this->relayPool->getLocalRelay();
        if (!$localRelay) {
            $io->error('Local relay not configured. Please set NOSTR_DEFAULT_RELAY environment variable.');
            return Command::FAILURE;
        }

        $io->title('Article Hydration Worker');
        $io->info(sprintf('Subscribing to local relay: %s', $localRelay));
        $io->info('Listening for article events (kind 30023)...');
        $io->newLine();

        try {
            // Start the long-lived subscription
            // This blocks forever and processes events via the callback
            $this->relayPool->subscribeLocalArticles(
                function (object $event, string $relayUrl) use ($io) {
                    $timestamp = date('Y-m-d H:i:s');
                    $eventId = substr($event->id ?? 'unknown', 0, 16) . '...';
                    $pubkey = substr($event->pubkey ?? 'unknown', 0, 16) . '...';
                    $kind = $event->kind ?? 'unknown';
                    $title = '';

                    // Extract title from tags if available
                    if (isset($event->tags) && is_array($event->tags)) {
                        foreach ($event->tags as $tag) {
                            if (is_array($tag) && isset($tag[0]) && $tag[0] === 'title' && isset($tag[1])) {
                                $title = mb_substr($tag[1], 0, 50);
                                if (mb_strlen($tag[1]) > 50) {
                                    $title .= '...';
                                }
                                break;
                            }
                        }
                    }

                    // Log to console
                    $io->writeln(sprintf(
                        '[%s] <fg=green>Event received:</> %s (kind: %s, pubkey: %s)%s',
                        $timestamp,
                        $eventId,
                        $kind,
                        $pubkey,
                        $title ? ' - ' . $title : ''
                    ));

                    // Project the event to the database
                    try {
                        $this->projector->projectArticleFromEvent($event, $relayUrl);
                        $io->writeln(sprintf(
                            '[%s] <fg=green>✓</> Article saved to database',
                            date('Y-m-d H:i:s')
                        ));
                    } catch (\InvalidArgumentException $e) {
                        // Invalid event (wrong kind, bad signature, etc.)
                        $io->writeln(sprintf(
                            '[%s] <fg=yellow>⚠</> Skipped invalid event: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    } catch (\Exception $e) {
                        // Database or other errors
                        $io->writeln(sprintf(
                            '[%s] <fg=red>✗</> Error saving article: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    }

                    $io->newLine();
                }
            );

            // @phpstan-ignore-next-line - This line should never be reached (infinite loop in subscribeLocalArticles)
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Subscription failed: ' . $e->getMessage());
            $this->logger->error('Article hydration worker failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}

