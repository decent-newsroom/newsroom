<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StaticController extends AbstractController
{

    /**
     * Lightweight healthcheck endpoint used by Docker HEALTHCHECK and load balancers.
     * Must NOT touch sessions, cache, or the database so it always responds quickly.
     */
    #[Route('/up', name: 'healthcheck', methods: ['GET', 'HEAD'])]
    public function healthcheck(): Response
    {
        return new Response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    #[Route('/about', name: 'app_static_about')]
    public function about(): Response
    {
        return $this->render('static/about.html.twig');
    }

    #[Route('/roadmap', name: 'app_static_roadmap')]
    public function roadmap(): Response
    {
        $path = $this->getParameter('kernel.project_dir') . '/ROADMAP.md';
        $markdown = file_exists($path) ? file_get_contents($path) : '';

        // Strip the top-level heading — the template provides its own
        $markdown = preg_replace('/^#\s+.+\n*/m', '', $markdown, 1);

        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $converter = new MarkdownConverter($environment);
        $html = $converter->convert($markdown)->getContent();

        return $this->render('static/roadmap.html.twig', [
            'roadmapHtml' => $html,
        ]);
    }

    #[Route('/pricing', name: 'app_static_pricing')]
    public function pricing(): Response
    {
        return $this->render('static/pricing.html.twig');
    }

    #[Route('/tos', name: 'app_static_tos')]
    public function tos(): Response
    {
        return $this->render('static/tos.html.twig');
    }

    #[Route('/changelog', name: 'app_static_changelog')]
    public function changelog(): Response
    {
        $path = $this->getParameter('kernel.project_dir') . '/CHANGELOG.md';
        $markdown = file_exists($path) ? file_get_contents($path) : '';

        // Strip the top-level heading — the template provides its own
        $markdown = preg_replace('/^#\s+CHANGELOG\s*\n*/i', '', $markdown, 1);

        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $converter = new MarkdownConverter($environment);
        $html = $converter->convert($markdown)->getContent();

        return $this->render('static/changelog.html.twig', [
            'changelogHtml' => $html,
        ]);
    }

    #[Route('/manifest.webmanifest', name: 'pwa_manifest')]
    public function manifest(): Response
    {
        return $this->render('static/manifest.webmanifest.twig', [], new Response('', 200, ['Content-Type' => 'application/manifest+json']));
    }

    #[Route('/unfold', name: 'unfold')]
    public function unfold(): Response
    {
        return $this->render('static/unfold.html.twig');
    }

    #[Route('/essayist', name: 'app_static_essayist', methods: ['GET'])]
    public function essayist(Request $request, UserEntityRepository $userRepository): Response
    {
        $user = $this->getUser();
        $roles = $user instanceof User ? $user->getRoles() : [];

        return $this->render('static/essayist.html.twig', [
            'isMember'         => in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true),
            'isPending'        => in_array(RolesEnum::ESSAYIST_CANDIDATE->value, $roles, true),
            'isEarlyBird'      => in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $roles, true),
            'memberCount'      => $userRepository->countByRole(RolesEnum::ESSAYIST_MEMBER->value),
            'joinStatus'       => $request->query->get('join_status'),
            'launchDate'       => new \DateTimeImmutable('2026-06-01'),
            'earlyBirdDeadline'=> new \DateTimeImmutable('2026-05-31'),
        ]);
    }

    #[Route('/essayist/early-bird', name: 'app_static_essayist_early_bird', methods: ['POST'])]
    public function claimEarlyBird(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('essayist_early_bird', $token)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'invalid_csrf']);
        }

        $roles = $user->getRoles();
        if (in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $roles, true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'already_early_bird']);
        }

        $user->addRole(RolesEnum::ESSAYIST_EARLY_BIRD->value);
        $user->addRole(RolesEnum::ESSAYIST_MEMBER->value);
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->redirectToRoute('app_static_essayist', ['join_status' => 'early_bird_claimed']);
    }

    #[Route('/essayist/request-access', name: 'app_static_essayist_request_access', methods: ['POST'])]
    public function requestEssayistAccess(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
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
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->redirectToRoute('app_static_essayist', ['join_status' => 'request_created']);
    }

    #[Route('/start-blog', name: 'start_blog')]
    public function startBlog(): Response
    {
        return $this->redirectToRoute('blog_journey_landing');
    }

    #[Route('/api/static-routes', name: 'api_static_routes', methods: ['GET'])]
    public function getStaticRoutes(): JsonResponse
    {
        $staticRoutes = [
            '/about',
            '/changelog',
            '/essayist',
            '/pricing',
            '/roadmap',
            '/tos',
            '/unfold',
            '/start-blog',
        ];

        return new JsonResponse([
            'routes' => $staticRoutes,
            'cacheName' => 'newsroom-static-v1'
        ]);
    }

    #[Route('/admin/cache', name: 'admin_cache', methods: ['GET'])]
    public function cacheManagement(): Response
    {
        return $this->render('admin/cache.html.twig');
    }
}
