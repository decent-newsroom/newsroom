<?php

declare(strict_types=1);

namespace App\Controller;

use App\Util\ForumTopics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search')]
    public function index(): Response
    {
        return $this->render('pages/search.html.twig', [
            'topics' => ForumTopics::TOPICS,
        ]);
    }
}
