<?php

declare(strict_types=1);

namespace App\Controller\Media;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page controller for the multimedia manager.
 *
 * Renders the main manager page; data loading is handled via Stimulus
 * controllers calling the API endpoints.
 */
class MediaManagerController extends AbstractController
{
    #[Route('/media-manager', name: 'media_manager')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('media_manager/index.html.twig');
    }
}

