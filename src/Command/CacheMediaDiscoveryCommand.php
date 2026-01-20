<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\FetchMediaEventsMessage;
use App\Repository\EventRepository;
use App\Service\MutedPubkeysService;
use Psr\Log\LoggerInterface;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(
    name: 'app:cache-media-discovery',
    description: 'Fetch and cache media events for the discovery page',
)]
class CacheMediaDiscoveryCommand extends Command
{
    private const CACHE_TTL = 32500; // 9ish hours in seconds

    // Hardcoded topic to hashtag mapping (same as controller)
    private const TOPIC_HASHTAGS = [
        'photography' => ['photography', 'photo', 'photostr', 'photographer', 'photos', 'picture', 'image', 'images', 'gallery', 'coffee'],
        'nature' => ['nature', 'landscape', 'wildlife', 'outdoor', 'naturephotography', 'pets', 'catstr', 'dogstr',
            'flowers', 'forest', 'mountains', 'beach', 'sunset', 'sunrise'],
        'travel' => ['travel', 'traveling', 'wanderlust', 'adventure', 'explore', 'city', 'vacation', 'trip'],
    ];

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ParameterBagInterface $params,
        private readonly MessageBusInterface $messageBus,
        private readonly EventRepository $eventRepository,
        private readonly MutedPubkeysService $mutedPubkeysService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force refresh even if cache is valid')
            ->setHelp('This command fetches media events from Nostr relays and caches them for the discovery page.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = true; // Always force refresh for this command

        $io->title('Media Discovery Cache Update');

        try {
            // Get all hashtags from all topics
            $allHashtags = [];
            foreach (array_keys(self::TOPIC_HASHTAGS) as $topic) {
                $allHashtags = array_merge($allHashtags, self::TOPIC_HASHTAGS[$topic]);
            }

            $env = $this->params->get('kernel.environment');
            $cacheKey = 'media_discovery_events_all_prod_' . $env;

            if ($force) {
                $io->info('Force refresh enabled - deleting existing cache');
                $this->cache->delete($cacheKey);
            }

            $io->info(sprintf('Dispatching async media fetch for %d hashtags...', count($allHashtags)));

            // Dispatch async message to fetch and persist media events
            $message = new FetchMediaEventsMessage($allHashtags, [20, 21, 22]);
            $this->messageBus->dispatch($message);

            $io->success('Dispatched media fetch message to async worker');

            // Query from database and rebuild cache
            $io->info('Querying media events from database...');
            $startTime = microtime(true);

            // Get muted pubkeys for filtering
            $excludedPubkeys = $this->mutedPubkeysService->getMutedPubkeys();
            $io->comment(sprintf('Excluding %d muted pubkeys', count($excludedPubkeys)));

            // Fetch and cache the events from database
            $mediaEvents = $this->cache->get($cacheKey, function () use ($io, $excludedPubkeys) {
                $io->comment('Cache miss - querying from database...');

                // Query non-NSFW media events from database
                $events = $this->eventRepository->findNonNSFWMediaEvents(
                    [20, 21, 22],
                    $excludedPubkeys,
                    500
                );

                $io->comment(sprintf('Fetched %d events from database', count($events)));

                // Convert Event entities to simple objects for caching
                $mediaEvents = [];
                $nip19 = new Nip19Helper();

                foreach ($events as $event) {
                    $obj = new \stdClass();
                    $obj->id = $event->getId();
                    $obj->pubkey = $event->getPubkey();
                    $obj->created_at = $event->getCreatedAt();
                    $obj->kind = $event->getKind();
                    $obj->tags = $event->getTags();
                    $obj->content = $event->getContent();
                    $obj->sig = $event->getSig();
                    $obj->noteId = $nip19->encodeNote($event->getId());

                    $mediaEvents[] = $obj;
                }

                $this->logger->info('Media discovery cache updated from database', [
                    'event_count' => count($mediaEvents),
                ]);

                return $mediaEvents;
            });

            $duration = round(microtime(true) - $startTime, 2);

            $io->success([
                sprintf('Successfully cached %d media events', count($mediaEvents)),
                sprintf('Duration: %s seconds', $duration),
                sprintf('Cache TTL: %d seconds (%d hours)', self::CACHE_TTL, self::CACHE_TTL / 3600),
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to cache media events: ' . $e->getMessage());
            $this->logger->error('Media discovery cache update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}

