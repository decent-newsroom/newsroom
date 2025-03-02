<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Service\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager,
        private readonly NostrClient $nostrClient)
    {
    }

    /**
     * @throws \Exception
     */
    #[Route('/', name: 'default')]
    public function index(): Response
    {
        $original = $this->entityManager->getRepository(Article::class)->findBy([], ['createdAt' => 'DESC'], 20);

        $list = array_filter($original, function ($obj) {
            return !empty($obj->getSlug());
        });

        // deduplicate by slugs
        $deduplicated = [];
        foreach ($list as $item) {
            if (!key_exists((string) $item->getSlug(), $deduplicated)) {
                $deduplicated[(string) $item->getSlug()] = $item;
            }
        }

        $npubs = array_map(function($obj) {
            return $obj->getPubkey();
        }, $list);

        $this->nostrClient->getMetadata(array_unique($npubs));

        return $this->render('home.html.twig', [
            'list' => array_values($deduplicated)
        ]);
    }
}
