<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    #[Route('/about')]
    public function about(): Response
    {
        return $this->render('static/about.html.twig');
    }

    #[Route('/roadmap')]
    public function roadmap(): Response
    {
        return $this->render('static/roadmap.html.twig');
    }

    #[Route('/pricing')]
    public function pricing(): Response
    {
        return $this->render('static/pricing.html.twig');
    }

    #[Route('/tos')]
    public function tos(): Response
    {
        return $this->render('static/tos.html.twig');
    }

    #[Route('/manifest.webmanifest', name: 'pwa_manifest')]
    public function manifest(): Response
    {
        return $this->render('static/manifest.webmanifest.twig', [], new Response('', 200, ['Content-Type' => 'application/manifest+json']));
    }

    #[Route('/landing', name: 'landing')]
    public function landing(): Response
    {
        return $this->render('static/landing.html.twig');
    }

    #[Route('/unfold', name: 'unfold')]
    public function unfold(): Response
    {
        return $this->render('static/unfold.html.twig');
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
            '/roadmap',
            '/tos',
            '/landing',
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
