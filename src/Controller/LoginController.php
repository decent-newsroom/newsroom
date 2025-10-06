<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET','POST'])]
     public function index(#[CurrentUser] ?User $user, Request $request): Response
    {
        if (null !== $user) {
            // Authenticated: for API calls still return JSON for backward compatibility.
            if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept',''), 'application/json')) {
                return new JsonResponse(['message' => 'Authentication Successful'], 200);
            }
            return $this->render('login/index.html.twig', [ 'authenticated' => true ]);
        }

        // If this is an authentication attempt with Authorization header let the security layer handle (401 JSON fallback)
        if ($request->headers->has('Authorization')) {
            return new JsonResponse(['message' => 'Unauthenticated'], 401);
        }

        // Default: render login page with Amber QR.
        return $this->render('login/index.html.twig', [ 'authenticated' => false ]);
    }

    #[Route('/login/signer', name: 'app_login_signer', methods: ['GET'])]
    public function signer(#[CurrentUser] ?User $user): Response
    {
        if (null !== $user) {
            return $this->redirectToRoute('newsstand');
        }
        return $this->render('login/amber.html.twig');
    }
}
