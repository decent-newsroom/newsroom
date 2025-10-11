<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CommentController extends AbstractController
{
    #[Route('/comments/publish', name: 'comment_publish', methods: ['POST'])]
    public function publish(Request $request, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        // CSRF check
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!$csrfToken || !$csrfTokenManager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('comment_publish', $csrfToken))) {
            return new JsonResponse(['message' => 'Invalid CSRF token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['event'])) {
            return new JsonResponse(['message' => 'Invalid request'], 400);
        }

        // Here you would validate and process the NIP-22 event
        // For now, just return success for integration testing
        return new JsonResponse(['status' => 'ok']);
    }
}

