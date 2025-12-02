<?php

namespace App\Command;

use App\Repository\HighlightRepository;
use App\Service\HighlightService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:debug-highlights',
    description: 'Debug highlights for an article coordinate',
)]
class DebugHighlightsCommand extends Command
{
    public function __construct(
        private readonly HighlightRepository $highlightRepository,
        private readonly HighlightService $highlightService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('coordinate', InputArgument::OPTIONAL, 'Article coordinate (kind:pubkey:slug)')
            ->setHelp('Debug highlights storage and retrieval. Run without arguments to see all stored coordinates.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $coordinate = $input->getArgument('coordinate');

        if (!$coordinate) {
            // List all stored coordinates
            $coordinates = $this->highlightRepository->getAllArticleCoordinates();

            $io->title('All Article Coordinates in Database');
            $io->writeln(sprintf('Found %d unique coordinates:', count($coordinates)));
            $io->newLine();

            foreach ($coordinates as $coord) {
                $count = count($this->highlightRepository->findByArticleCoordinate($coord));
                $io->writeln(sprintf('  %s (%d highlights)', $coord, $count));
            }

            $io->newLine();
            $io->info('Run with a coordinate argument to see details: app:debug-highlights "30023:pubkey:slug"');

            return Command::SUCCESS;
        }

        // Debug specific coordinate
        $io->title('Highlight Debug for: ' . $coordinate);

        // Check database
        $io->section('Database Check');
        $dbHighlights = $this->highlightRepository->findByArticleCoordinate($coordinate);
        $io->writeln(sprintf('Found %d highlights in database', count($dbHighlights)));

        if (count($dbHighlights) > 0) {
            $io->table(
                ['Event ID', 'Content Preview', 'Created At', 'Cached At'],
                array_map(function($h) {
                    return [
                        substr($h->getEventId(), 0, 16) . '...',
                        substr($h->getContent(), 0, 50) . '...',
                        date('Y-m-d H:i:s', $h->getCreatedAt()),
                        $h->getCachedAt()->format('Y-m-d H:i:s'),
                    ];
                }, array_slice($dbHighlights, 0, 5))
            );

            if (count($dbHighlights) > 5) {
                $io->writeln(sprintf('... and %d more', count($dbHighlights) - 5));
            }
        }

        // Check cache status
        $io->section('Cache Status');
        $needsRefresh = $this->highlightRepository->needsRefresh($coordinate, 24);
        $lastCache = $this->highlightRepository->getLastCacheTime($coordinate);

        $io->writeln(sprintf('Needs refresh (24h): %s', $needsRefresh ? 'YES' : 'NO'));
        $io->writeln(sprintf('Last cached: %s', $lastCache ? $lastCache->format('Y-m-d H:i:s') : 'Never'));

        // Try to fetch through service
        $io->section('Service Fetch Test');
        $io->writeln('Fetching highlights through HighlightService...');

        try {
            $highlights = $this->highlightService->getHighlightsForArticle($coordinate);
            $io->success(sprintf('Successfully fetched %d highlights', count($highlights)));

            if (count($highlights) > 0) {
                $io->table(
                    ['Content Preview', 'Created At', 'Pubkey'],
                    array_map(function($h) {
                        return [
                            substr($h['content'], 0, 50) . '...',
                            date('Y-m-d H:i:s', $h['created_at']),
                            substr($h['pubkey'], 0, 16) . '...',
                        ];
                    }, array_slice($highlights, 0, 5))
                );
            }
        } catch (\Exception $e) {
            $io->error('Failed to fetch highlights: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

