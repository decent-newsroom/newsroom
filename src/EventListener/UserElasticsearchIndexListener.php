<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use Psr\Log\LoggerInterface;

/**
 * Automatically indexes users to Elasticsearch when they are created or updated
 */
#[AsDoctrineListener(event: Events::postPersist, priority: 500, connection: 'default')]
#[AsDoctrineListener(event: Events::postUpdate, priority: 500, connection: 'default')]
class UserElasticsearchIndexListener
{
    public function __construct(
        private readonly ObjectPersisterInterface $userPersister,
        private readonly LoggerInterface $logger,
        private readonly bool $elasticsearchEnabled = true
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->indexUser($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->indexUser($args->getObject());
    }

    private function indexUser(object $entity): void
    {
        if (!$entity instanceof User) {
            return;
        }

        if (!$this->elasticsearchEnabled) {
            return;
        }

        try {
            $this->userPersister->insertOne($entity);
            $this->logger->info("User indexed to Elasticsearch: {$entity->getNpub()}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to index user to Elasticsearch: {$entity->getNpub()}", [
                'error' => $e->getMessage()
            ]);
        }
    }
}

