<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ArticleEventProjector;
use App\Service\Nostr\NostrRelayPool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Subscribes to the internal Essayist relay (`strfry-essayist:7779`) for kind
 * 30023/30024 longform events and persists them through the standard
 * `ArticleEventProjector`. The factory honors the NIP-70 `["-"]` (protected)
 * tag at projection time and flips `essayist_exclusive = true` so the rest
 * of the app keeps these articles out of anonymous feeds and searches.
 *
 * The Essayist relay is only reachable on the internal Docker network and
 * only when the `essayist` compose profile is active. If the configured URL
 * is empty, the command exits successfully (no-op) so it can be safely
 * scheduled even when the profile is off. If the URL is set but the relay
 * is unreachable, the command waits and retries with backoff rather than
 * crash-looping.
 */
#[AsCommand(
    name: 'essayist:subscribe-relay',
    description: 'Subscribe to strfry-essayist for longform articles (kinds 30023, 30024) and persist them to the database'
)]
final class SubscribeEssayistRelayCommand extends Command
{
    /** Seconds to wait between reconnect attempts when the relay is unreachable. */
    private const RECONNECT_BACKOFF_SECONDS = 30;

    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly ArticleEventProjector $projector,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'essayist.relay_internal_url')]
        private readonly string $internalRelayUrl = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Subscribes to the internal strfry-essayist relay for article events ' .
            '(kinds 30023, 30024) and persists them to the database via ArticleEventProjector. ' .
            'Articles carrying the NIP-70 ["-"] protected tag are flagged essayist_exclusive ' .
            'by ArticleFactory at projection time. This command is intended to run as part of ' .
            'app:run-relay-workers when the essayist Docker profile is active.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->internalRelayUrl === '') {
            $io->warning('Essayist relay URL is not configured (essayist.relay_internal_url is empty). Exiting cleanly — nothing to subscribe to.');
            return Command::SUCCESS;
        }

        $io->title('Essayist Article Hydration Worker');
        $io->info(sprintf('Subscribing to essayist relay: %s', $this->internalRelayUrl));
        $io->info('Listening for article events (kinds 30023, 30024)...');
        $io->newLine();

        // Long-lived loop: on disconnect, wait and reconnect. `subscribeLocal`
        // blocks until the relay closes the subscription or throws — both are
        // treated as transient because the essayist relay is internal-only and
        // any disconnect is operationally noisy but recoverable.
        while (true) {
            try {
                $this->relayPool->subscribeLocal(
                    [30023, 30024],
                    function (object $event, string $relayUrl) use ($io): void {
                        $eventId = substr($event->id ?? 'unknown', 0, 16) . '...';
                        $pubkey  = substr($event->pubkey ?? 'unknown', 0, 16) . '...';
                        $kind    = $event->kind ?? 'unknown';
                        $isProtected = false;
                        if (isset($event->tags) && is_array($event->tags)) {
                            foreach ($event->tags as $tag) {
                                if (is_array($tag) && ($tag[0] ?? null) === '-') {
                                    $isProtected = true;
                                    break;
                                }
                            }
                        }

                        $io->writeln(sprintf(
                            '[%s] <fg=green>Essayist event:</> %s (kind: %s, pubkey: %s%s)',
                            date('Y-m-d H:i:s'),
                            $eventId,
                            $kind,
                            $pubkey,
                            $isProtected ? ', <fg=yellow>NIP-70 protected</>' : ''
                        ));

                        try {
                            // Only flag `essayist_exclusive` when the event
                            // carries the NIP-70 `["-"]` tag *and* arrived
                            // here (= from strfry-essayist). The combination
                            // is what disambiguates the otherwise-generic
                            // NIP-70 marker: an author who tags `-` on the
                            // members-only relay is using our exclusive
                            // feature; an author who tags `-` on a public
                            // relay (and whose event reaches us via the
                            // local strfry router or NIP-65 outbox fetch)
                            // is exercising their own privacy choice and
                            // must not be shadow-hidden from anonymous
                            // readers here.
                            $this->projector->projectArticleFromEvent(
                                $event,
                                $relayUrl,
                                $isProtected,
                            );
                            $io->writeln(sprintf(
                                '[%s] <fg=green>✓</> Article saved%s',
                                date('Y-m-d H:i:s'),
                                $isProtected ? ' (essayist_exclusive)' : ''
                            ));
                        } catch (\InvalidArgumentException $e) {
                            $io->writeln(sprintf(
                                '[%s] <fg=yellow>⚠</> Skipped invalid event: %s',
                                date('Y-m-d H:i:s'),
                                $e->getMessage()
                            ));
                        } catch (\Throwable $e) {
                            $io->writeln(sprintf(
                                '[%s] <fg=red>✗</> Error saving article: %s',
                                date('Y-m-d H:i:s'),
                                $e->getMessage()
                            ));
                        }
                    },
                    'essayist-articles',
                    null,
                    $this->internalRelayUrl,
                );
                // subscribeLocal blocks forever; reaching here means it returned cleanly
                // @phpstan-ignore-next-line - defensive: subscribeLocal's infinite loop should not return
                $io->warning('Essayist subscription returned without an exception — reconnecting after backoff.');
            } catch (\Throwable $e) {
                $this->logger->warning('Essayist subscription disconnected; will retry', [
                    'relay' => $this->internalRelayUrl,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                $io->writeln(sprintf(
                    '[%s] <fg=yellow>Essayist relay disconnected (%s). Retrying in %ds...</>',
                    date('Y-m-d H:i:s'),
                    $e->getMessage(),
                    self::RECONNECT_BACKOFF_SECONDS,
                ));
            }

            sleep(self::RECONNECT_BACKOFF_SECONDS);
        }
    }
}



