<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Service\ChatCommunityResolver;
use App\ChatBundle\Service\ChatProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChatProfileController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityResolver $communityResolver,
        private readonly ChatProfileService $profileService,
    ) {}

    public function index(Request $request): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getUser();
        if (!$user instanceof ChatUser) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $displayName = trim($request->request->get('display_name', ''));
            $about = trim($request->request->get('about', '')) ?: null;

            if ($displayName !== '') {
                $this->profileService->updateProfile($user, $displayName, $about);
                $this->addFlash('success', 'Profile updated.');
                return $this->redirectToRoute('chat_profile');
            }
        }

        return $this->render('@Chat/profile/edit.html.twig', [
            'community' => $community,
            'user' => $user,
        ]);
    }
}

