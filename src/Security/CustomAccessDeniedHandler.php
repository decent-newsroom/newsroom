<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;

class CustomAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    private $twig;
    private $router;

    public function __construct(\Twig\Environment $twig, RouterInterface $router)
    {
        $this->twig = $twig;
        $this->router = $router;
    }

    public function handle(Request $request, \Exception $accessDeniedException): ?Response
    {
        // If not logged in, redirect to login
        if (!$request->getUser()) {
            return new RedirectResponse($this->router->generate('app_login'));
        }
        // Otherwise, render a custom error page
        $content = $this->twig->render('bundles/TwigBundle/Exception/error403.html.twig');
        return new Response($content, 403);
    }
}
