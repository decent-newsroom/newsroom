<?php

namespace App\Provider;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Provider\PagerfantaPager;
use FOS\ElasticaBundle\Provider\PagerInterface;
use FOS\ElasticaBundle\Provider\PagerProviderInterface;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;

class UserProvider implements PagerProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function provide(array $options = []): PagerInterface
    {
        // Get all users for indexing
        $users = $this->entityManager->getRepository(User::class)->findAll();
        return new PagerfantaPager(new Pagerfanta(new ArrayAdapter($users)));
    }
}

