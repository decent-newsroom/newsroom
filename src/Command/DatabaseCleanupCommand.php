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

#[AsCommand(name: 'db:cleanup', description: 'Remove articles with do_not_index rating')]
class


 DatabaseCleanupCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = 100;
        $totalDeleted = 0;

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
        } while ($batchCount === $batchSize);

        if ($totalDeleted === 0) {
            $output->writeln('<info>No items found.</info>');
        } else {
            $output->writeln('<comment>Deleted ' . $totalDeleted . ' items.</comment>');
        }

        return Command::SUCCESS;
    }
}
