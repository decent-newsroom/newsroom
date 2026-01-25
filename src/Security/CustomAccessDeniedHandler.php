<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Bundle\SecurityBundle\Security;

class CustomAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    private $twig;
    private $router;
    private $security;

    public function __construct(\Twig\Environment $twig, RouterInterface $router, Security $security)
    {
        $this->twig = $twig;
        $this->router = $router;
        $this->security = $security;
    }

    public function handle(Request $request, \Symfony\Component\Security\Core\Exception\AccessDeniedException $accessDeniedException): ?Response
    {
        // Check if request expects JSON (API requests)
        $acceptHeader = $request->headers->get('Accept', '');
        $contentType = $request->headers->get('Content-Type', '');
        $isJsonRequest = str_contains($acceptHeader, 'application/json') ||
                         str_contains($contentType, 'application/json') ||
                         $request->isXmlHttpRequest();

        // If not logged in, redirect to login (for HTML) or return JSON error
        $user = $this->security->getUser();
        if (!$user) {
            if ($isJsonRequest) {
                return new JsonResponse(
                    ['error' => 'Authentication required', 'message' => 'You must be logged in to access this resource.'],
                    Response::HTTP_UNAUTHORIZED
                );
            }
            return new RedirectResponse($this->router->generate('app_login'));
        }

        // User is logged in but doesn't have permission
        if ($isJsonRequest) {
            return new JsonResponse(
                ['error' => 'Access denied', 'message' => 'You do not have permission to access this resource.'],
                Response::HTTP_FORBIDDEN
            );
        }

        // For HTML requests, render a custom error page
        $content = $this->twig->render('bundles/TwigBundle/Exception/error403.html.twig');
        return new Response($content, 403);
    }
}


