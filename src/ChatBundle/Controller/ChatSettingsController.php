<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Service\ChatCommunityResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class ChatSettingsController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityResolver $communityResolver,
    ) {}

    public function index(): Response
    {
        $community = $this->communityResolver->resolve();

        return $this->render('@Chat/settings.html.twig', [
            'community' => $community,
        ]);
    }
}

