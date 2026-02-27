<?php

namespace App\EventListener;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Event\PostIndexPopulateEvent;

class PopulateListener
{
    public function __construct( private readonly EntityManagerInterface $entityManager)
    {
    }

    public function postIndexPopulate(PostIndexPopulateEvent $event): void
    {
        $batchSize = 100;
        $processed = 0;

        $query = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Article::class, 'a')
            ->where('a.indexStatus = :status')
            ->setParameter('status', IndexStatusEnum::TO_BE_INDEXED)
            ->getQuery();

        foreach ($query->toIterable() as $article) {
            $article->setIndexStatus(IndexStatusEnum::INDEXED);
            $processed++;

            if ($processed % $batchSize === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
