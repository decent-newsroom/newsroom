<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\MagazineProjector;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:project-magazines',
    description: 'Project magazine indices from Nostr events to Magazine entities',
)]
class ProjectMagazinesCommand extends Command
{
    public function __construct(
        private readonly MagazineProjector $projector,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Specific magazine slug to project')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force projection even if recently updated')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $specificSlug = $input->getArgument('slug');

        if ($specificSlug) {
            $io->info("Projecting magazine: $specificSlug");
            $magazine = $this->projector->projectMagazine($specificSlug);
            if ($magazine) {
                $io->success("Projected: {$magazine->getTitle()} ({$specificSlug})");
            } else {
                $io->warning("Magazine not found or not a top-level magazine: $specificSlug");
            }
            return Command::SUCCESS;
        }

        // Find all top-level magazine events and project them
        $allIndices = $this->em->getRepository(Event::class)->findBy([
            'kind' => KindsEnum::PUBLICATION_INDEX,
        ]);

        // Deduplicate by slug, keeping newest
        $bySlug = [];
        foreach ($allIndices as $event) {
            $slug = $event->getSlug();
            if ($slug === null) {
                continue;
            }
            // Check if it's a magazine type
            $tags = $event->getTags();
            $isMagType = false;
            $isTopLevel = false;
            foreach ($tags as $tag) {
                if (($tag[0] ?? '') === 'type' && ($tag[1] ?? '') === 'magazine') {
                    $isMagType = true;
                }
                if (($tag[0] ?? '') === 'a') {
                    $parts = explode(':', $tag[1] ?? '');
                    if (($parts[0] ?? '') === (string) KindsEnum::PUBLICATION_INDEX->value) {
                        $isTopLevel = true;
                    }
                }
            }
            if ($isMagType && $isTopLevel) {
                if (!isset($bySlug[$slug]) || $event->getCreatedAt() > $bySlug[$slug]->getCreatedAt()) {
                    $bySlug[$slug] = $event;
                }
            }
        }

        if (empty($bySlug)) {
            $io->warning('No magazine events found');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d magazine(s) to project', count($bySlug)));
        $projected = 0;

        foreach ($bySlug as $slug => $event) {
            try {
                $magazine = $this->projector->projectMagazine($slug);
                if ($magazine) {
                    $io->writeln("  ✓ {$magazine->getTitle()} ({$slug})");
                    $projected++;
                } else {
                    $io->writeln("  ⚠ Skipped: {$slug}");
                }
            } catch (\Throwable $e) {
                $io->writeln("  ✗ Failed: {$slug} — {$e->getMessage()}");
                $this->logger->error('Magazine projection failed', [
                    'slug' => $slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $io->success("Projected {$projected} of " . count($bySlug) . " magazine(s)");
        return Command::SUCCESS;
    }
}
