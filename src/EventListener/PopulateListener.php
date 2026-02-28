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

        do {
            $articles = $this->entityManager->getRepository(Article::class)
                ->findBy(['indexStatus' => IndexStatusEnum::TO_BE_INDEXED], ['id' => 'ASC'], $batchSize);

            $batchCount = count($articles);

            foreach ($articles as $article) {
                $article->setIndexStatus(IndexStatusEnum::INDEXED);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        } while ($batchCount === $batchSize);
    }
}
