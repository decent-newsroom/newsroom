<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HighlightRepository;
use App\Repository\ArticleRepository;
use App\Service\RedisCacheService;
use App\Service\RedisViewStore;
use App\ReadModel\RedisView\RedisViewFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'app:cache-latest-highlights',
    description: 'Cache the latest highlights list with articles and author profiles'
)]
class CacheLatestHighlightsCommand extends Command
{
    public function __construct(
        private readonly HighlightRepository $highlightRepository,
        private readonly RedisCacheService $redisCacheService,
        private readonly RedisViewStore $viewStore,
        private readonly RedisViewFactory $viewFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Maximum number of highlights to cache',
            50
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');

        $output->writeln("<comment>Fetching latest {$limit} highlights from database...</comment>");

        try {
            // Get latest highlights with their articles
            $highlightsWithArticles = $this->highlightRepository->findLatestWithArticles($limit);
            $output->writeln(sprintf('<info>Found %d highlights</info>', count($highlightsWithArticles)));

            if (empty($highlightsWithArticles)) {
                $output->writeln('<comment>No highlights found in database</comment>');
                return Command::SUCCESS;
            }

            // Collect unique pubkeys for batch metadata fetch
            $pubkeys = [];
            foreach ($highlightsWithArticles as $item) {
                $highlight = $item['highlight'];
                $article = $item['article'];

                if ($highlight->getPubkey()) {
                    $pubkeys[] = $highlight->getPubkey();
                }
                if ($article && $article->getPubkey()) {
                    $pubkeys[] = $article->getPubkey();
                }
            }
            $pubkeys = array_unique(array_filter($pubkeys));

            $output->writeln(sprintf('<comment>Pre-fetching metadata for %d unique authors...</comment>', count($pubkeys)));
            $metadataMap = $this->redisCacheService->getMultipleMetadata($pubkeys);
            $output->writeln(sprintf('<comment>Fetched %d author profiles</comment>', count($metadataMap)));

            // Build Redis view objects
            $output->writeln('<comment>Building Redis view objects...</comment>');
            $baseObjects = [];
            $skipped = 0;
            $skippedNoCoordinate = 0;
            $skippedNoArticle = 0;

            foreach ($highlightsWithArticles as $item) {
                $highlight = $item['highlight'];
                $article = $item['article'];

                // Skip if no article coordinate (highlight doesn't reference an article)
                if (!$highlight->getArticleCoordinate()) {
                    $skippedNoCoordinate++;
                    $this->logger->debug('Skipping highlight - no article coordinate', [
                        'highlight_id' => $highlight->getEventId(),
                    ]);
                    continue;
                }

                // Skip if article not found
                if (!$article) {
                    $skippedNoArticle++;
                    $this->logger->debug('Skipping highlight - article not found', [
                        'highlight_id' => $highlight->getEventId(),
                        'coordinate' => $highlight->getArticleCoordinate(),
                    ]);
                    continue;
                }

                // Get metadata for both authors
                $highlightAuthorMeta = $metadataMap[$highlight->getPubkey()] ?? null;
                $articleAuthorMeta = $metadataMap[$article->getPubkey()] ?? null;

                // Build base object
                try {
                    $baseObject = $this->viewFactory->highlightBaseObject(
                        $highlight,
                        $article,
                        $highlightAuthorMeta,
                        $articleAuthorMeta
                    );
                    $baseObjects[] = $baseObject;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to build highlight base object', [
                        'highlight_id' => $highlight->getEventId(),
                        'error' => $e->getMessage(),
                    ]);
                    $skipped++;
                }
            }

            if ($skippedNoCoordinate > 0) {
                $output->writeln(sprintf('<comment>Skipped %d highlights without article coordinates</comment>', $skippedNoCoordinate));
            }
            if ($skippedNoArticle > 0) {
                $output->writeln(sprintf('<comment>Skipped %d highlights with missing articles</comment>', $skippedNoArticle));
            }
            if ($skipped > 0) {
                $output->writeln(sprintf('<comment>Skipped %d highlights (errors)</comment>', $skipped));
            }

            // Store to Redis views
            if (!empty($baseObjects)) {
                $this->viewStore->storeLatestHighlights($baseObjects);
                $output->writeln(sprintf('<info>✓ Stored %d highlights to Redis views (view:highlights:latest)</info>', count($baseObjects)));
            } else {
                $output->writeln('<error>No valid highlights to cache</error>');
                return Command::FAILURE;
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            $this->logger->error('Failed to cache highlights', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}

