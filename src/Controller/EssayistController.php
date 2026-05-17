<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Service\Essayist\EssayistMembershipCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/essayist')]
class EssayistController extends AbstractController
{
    private const LAUNCH_DATE      = '2026-06-01';
    private const EARLY_BIRD_DEADLINE = '2026-05-31';

    #[Route('', name: 'app_static_essayist', methods: ['GET'])]
    public function index(Request $request, UserEntityRepository $userRepository): Response
    {
        $user  = $this->getUser();
        $roles = $user instanceof User ? $user->getRoles() : [];

        $launchDate = new \DateTimeImmutable(self::LAUNCH_DATE);

        return $this->render('static/essayist.html.twig', [
            'isMember'          => in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true),
            'isPending'         => in_array(RolesEnum::ESSAYIST_CANDIDATE->value, $roles, true),
            'isEarlyBird'       => in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $roles, true),
            'memberCount'       => $userRepository->countByRole(RolesEnum::ESSAYIST_MEMBER->value),
            'joinStatus'        => $request->query->get('join_status'),
            'launchDate'        => $launchDate,
            'isLaunched'        => new \DateTimeImmutable() >= $launchDate,
            'earlyBirdDeadline' => new \DateTimeImmutable(self::EARLY_BIRD_DEADLINE),
        ]);
    }

    #[Route('/early-bird', name: 'app_static_essayist_early_bird', methods: ['POST'])]
    public function claimEarlyBird(
        Request $request,
        EntityManagerInterface $em,
        EssayistMembershipCacheService $membershipCache,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('essayist_early_bird', $token)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'invalid_csrf']);
        }

        if (in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $user->getRoles(), true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'already_early_bird']);
        }

        $user->addRole(RolesEnum::ESSAYIST_EARLY_BIRD->value);
        $user->addRole(RolesEnum::ESSAYIST_MEMBER->value);
        $em->persist($user);
        $em->flush();

        $membershipCache->markApproved((string) $user->getNpub());

        return $this->redirectToRoute('app_static_essayist', ['join_status' => 'early_bird_claimed']);
    }

    #[Route('/request-access', name: 'app_static_essayist_request_access', methods: ['POST'])]
    public function requestAccess(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('essayist_request_access', $token)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'invalid_csrf']);
        }

        $roles = $user->getRoles();

        if (in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'already_member']);
        }

        if (in_array(RolesEnum::ESSAYIST_CANDIDATE->value, $roles, true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'already_pending']);
        }

        $user->addRole(RolesEnum::ESSAYIST_CANDIDATE->value);
        $em->persist($user);
        $em->flush();

        return $this->redirectToRoute('app_static_essayist', ['join_status' => 'request_created']);
    }
}

