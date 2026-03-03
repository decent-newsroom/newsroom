<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'app_locale_switch', methods: ['GET'])]
    public function switchLocale(string $locale, Request $request): Response
    {
        $enabledLocales = $this->getParameter('kernel.enabled_locales');

        if (!\in_array($locale, $enabledLocales, true)) {
            $locale = 'en';
        }

        $request->getSession()->set('_locale', $locale);

        // Redirect back to the referring page or home
        $referer = $request->headers->get('referer');

        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('home');
    }
}

