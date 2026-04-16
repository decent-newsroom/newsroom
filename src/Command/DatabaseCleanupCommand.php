<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'db:cleanup', description: 'Remove articles with do_not_index rating')]
class DatabaseCleanupCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = 100;
        $totalDeleted = 0;

        $io->title('Database Cleanup');
        $io->text('Removing articles with <comment>do_not_index</comment> status...');

        // Delete in batches to avoid memory exhaustion and long-running transactions
        do {
            $articles = $this->entityManager->getRepository(Article::class)
                ->findBy(['indexStatus' => IndexStatusEnum::DO_NOT_INDEX], null, $batchSize);

            $batchCount = count($articles);
            if ($batchCount === 0) {
                break;
            }

            foreach ($articles as $item) {
                $this->entityManager->remove($item);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            $totalDeleted += $batchCount;

            $io->text(sprintf('  Deleted batch of %d articles (total so far: %d)', $batchCount, $totalDeleted));
        } while ($batchCount === $batchSize);

        if ($totalDeleted === 0) {
            $io->success('No articles with do_not_index status found. Nothing to clean up.');
        } else {
            $io->success(sprintf('Cleanup complete. Deleted %d article(s).', $totalDeleted));
        }

        return Command::SUCCESS;
    }
}
