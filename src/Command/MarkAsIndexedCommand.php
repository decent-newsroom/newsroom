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

#[AsCommand(name: 'articles:indexed', description: 'Mark articles as indexed after populating')]
class MarkAsIndexedCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = 100;
        $count = 0;

        do {
            $articles = $this->entityManager->getRepository(Article::class)
                ->findBy(['indexStatus' => IndexStatusEnum::TO_BE_INDEXED], ['id' => 'ASC'], $batchSize);

            $batchCount = count($articles);

            foreach ($articles as $article) {
                $count++;
                $article->setIndexStatus(IndexStatusEnum::INDEXED);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        } while ($batchCount === $batchSize);

        $output->writeln($count . ' articles marked as indexed successfully.');

        return Command::SUCCESS;
    }
}
