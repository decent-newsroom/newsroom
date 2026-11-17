<?php

declare(strict_types=1);

namespace App\Controller\Subscription;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Old purchase links must never create another invoice. */
final class UpdateProController extends AbstractController
{
    #[Route('/subscription/updates-pro', name: 'updates_pro_index', methods: ['GET'])]
    #[Route('/subscription/updates-pro/subscribe/{tier}', name: 'updates_pro_subscribe', methods: ['GET', 'POST'])]
    #[Route('/subscription/updates-pro/renew/{tier}', name: 'updates_pro_renew', methods: ['GET', 'POST'])]
    public function retired(): Response
    {
        return $this->redirectToRoute('updates_index', status: Response::HTTP_SEE_OTHER);
    }
}
