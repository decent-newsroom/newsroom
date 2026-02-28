<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'articles:index', description: 'Persist selected articles to Elastic')]
class IndexArticlesCommand extends Command
{
    private const BATCH_SIZE = 100; // Define batch size

    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ObjectPersisterInterface $itemPersister)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processedCount = 0;
        $lastId = 0;

        // Fetch in batches using ID-based pagination (indexStatus is NOT changed here)
        do {
            $articles = $this->entityManager->createQueryBuilder()
                ->select('a')
                ->from(Article::class, 'a')
                ->where('a.indexStatus = :status')
                ->andWhere('a.id > :lastId')
                ->setParameter('status', IndexStatusEnum::TO_BE_INDEXED)
                ->setParameter('lastId', $lastId)
                ->orderBy('a.id', 'ASC')
                ->setMaxResults(self::BATCH_SIZE)
                ->getQuery()
                ->getResult();

            $batchCount = count($articles);
            if ($batchCount === 0) {
                break;
            }

            $this->flushAndPersistBatch($articles);
            $processedCount += $batchCount;

            // Track last ID for next batch
            $lastId = end($articles)->getId();

            $this->entityManager->clear();
        } while ($batchCount === self::BATCH_SIZE);

        $output->writeln("$processedCount items indexed in Elasticsearch.");
        return Command::SUCCESS;
    }

    private function flushAndPersistBatch(array $items): void
    {
        // Persist batch to Elasticsearch
        $this->itemPersister->replaceMany($items);
    }
}
