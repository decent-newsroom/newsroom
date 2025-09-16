<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReadingListController extends AbstractController
{
    #[Route('/reading-list', name: 'reading_list_index')]
    public function index(): Response
    {
        return $this->render('reading_list/index.html.twig');
    }

    #[Route('/reading-list/compose', name: 'reading_list_compose')]
    public function compose(): Response
    {
        return $this->render('reading_list/compose.html.twig');
    }
}
