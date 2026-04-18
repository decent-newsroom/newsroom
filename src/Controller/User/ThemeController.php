<?php

declare(strict_types=1);

namespace App\Controller\User;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ThemeController extends AbstractController
{
    private const ALLOWED_THEMES = ['dark', 'light', 'space'];

    #[Route('/theme/{theme}', name: 'app_theme_switch', methods: ['GET'])]
    public function switchTheme(string $theme, Request $request): Response
    {
        if (!\in_array($theme, self::ALLOWED_THEMES, true)) {
            $theme = 'dark';
        }

        $request->getSession()->set('theme', $theme);

        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return new JsonResponse(['theme' => $theme]);
        }

        $referer = $request->headers->get('referer');

        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('home');
    }
}

