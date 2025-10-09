<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\NostrClient;
use Psr\Log\LoggerInterface;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
        private readonly NostrClient $nostrClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
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
        // $force = $input->getOption('force');

        $io->title('Media Discovery Cache Update');

        try {
            // Get all hashtags from all topics
            $allHashtags = [];
            foreach (array_keys(self::TOPIC_HASHTAGS) as $topic) {
                $allHashtags = array_merge($allHashtags, self::TOPIC_HASHTAGS[$topic]);
            }

            $cacheKey = 'media_discovery_events_all';

            if ($force) {
                $io->info('Force refresh enabled - deleting existing cache');
                $this->cache->delete($cacheKey);
            }

            $io->info(sprintf('Fetching media events for %d hashtags...', count($allHashtags)));
            $startTime = microtime(true);

            // Fetch and cache the events
            $mediaEvents = $this->cache->get($cacheKey, function () use ($io, $allHashtags) {
                $io->comment('Cache miss - fetching from Nostr relays...');

                // Fetch media events that match these hashtags
                $mediaEvents = $this->nostrClient->getMediaEventsByHashtags($allHashtags);

                $io->comment(sprintf('Fetched %d total events', count($mediaEvents)));

                // Deduplicate by event ID
                $uniqueEvents = [];
                foreach ($mediaEvents as $event) {
                    if (!isset($uniqueEvents[$event->id])) {
                        $uniqueEvents[$event->id] = $event;
                    }
                }

                $mediaEvents = array_values($uniqueEvents);
                $io->comment(sprintf('After deduplication: %d unique events', count($mediaEvents)));

                // Filter out NSFW content
                $mediaEvents = $this->filterNSFW($mediaEvents);
                $io->comment(sprintf('After NSFW filter: %d events', count($mediaEvents)));

                // Encode event IDs as note1... for each event
                $nip19 = new Nip19Helper();
                foreach ($mediaEvents as $event) {
                    $event->noteId = $nip19->encodeNote($event->id);
                }

                $this->logger->info('Media discovery cache updated', [
                    'event_count' => count($mediaEvents),
                    'hashtags' => count($allHashtags),
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

    /**
     * Filter out NSFW content from events
     * Checks for content-warning tags and NSFW-related hashtags
     */
    private function filterNSFW(array $events): array
    {
        return array_filter($events, function($event) {
            if (!isset($event->tags) || !is_array($event->tags)) {
                return true; // Keep if no tags
            }

            foreach ($event->tags as $tag) {
                if (!is_array($tag) || count($tag) < 1) {
                    continue;
                }

                // Check for content-warning tag (NIP-32)
                if ($tag[0] === 'content-warning') {
                    return false;
                }

                // Check for L tag with NSFW marking
                if ($tag[0] === 'L' && count($tag) >= 2 && strtolower($tag[1]) === 'nsfw') {
                    return false;
                }

                // Check for hashtags that indicate NSFW content
                if ($tag[0] === 't' && count($tag) >= 2) {
                    $hashtag = strtolower($tag[1]);
                    if (in_array($hashtag, ['nsfw', 'adult', 'explicit', '18+', 'nsfl'])) {
                        return false;
                    }
                }
            }

            return true; // Keep the event if no NSFW markers found
        });
    }
}

