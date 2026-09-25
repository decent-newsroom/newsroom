<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use App\Util\IndexableArticleChecker;
use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'articles:index', description: 'Persist selected articles to Elastic')]
class IndexArticlesCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObjectPersisterInterface $itemPersister,
        private readonly IndexableArticleChecker $indexableArticleChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexedCount = 0;
        $mutedCount = 0;
        $lastId = 0;

        do {
            $articles = $this->entityManager->createQueryBuilder()
                ->select('a')
                ->from(Article::class, 'a')
                ->where('a.indexStatus = :status')
                ->andWhere('a.id > :lastId')
                ->setParameter('status', IndexStatusEnum::TO_BE_INDEXED)
                ->setParameter('lastId', $lastId)
                ->orderBy('a.id', \SortDirection::Ascending)
                ->setMaxResults(self::BATCH_SIZE)
                ->getQuery()
                ->getResult();

            $batchCount = count($articles);
            if ($batchCount === 0) {
                break;
            }

            $lastId = end($articles)->getId();
            $indexable = [];
            $mutedIds = [];

            foreach ($articles as $article) {
                if ($this->indexableArticleChecker->isMutedAuthor($article)) {
                    $mutedIds[] = (string) $article->getId();
                    $article->setIndexStatus(IndexStatusEnum::DO_NOT_INDEX);
                    continue;
                }

                if ($this->indexableArticleChecker->isIndexable($article)) {
                    $indexable[] = $article;
                }
            }

            // This command calls the persister directly, bypassing the FOS
            // indexable callback. Remove stale documents before changing status.
            if ($mutedIds !== []) {
                $this->itemPersister->deleteManyByIdentifiers($mutedIds);
                $mutedCount += count($mutedIds);
                $this->entityManager->flush();
            }

            if ($indexable !== []) {
                $this->itemPersister->replaceMany($indexable);
                $indexedCount += count($indexable);
            }

            $this->entityManager->clear();
        } while ($batchCount === self::BATCH_SIZE);

        $output->writeln(sprintf(
            '%d items indexed in Elasticsearch; %d muted-author articles excluded.',
            $indexedCount,
            $mutedCount,
        ));

        return Command::SUCCESS;
    }
}
