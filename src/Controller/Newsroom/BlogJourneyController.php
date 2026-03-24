<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Message\FetchAuthorArticlesMessage;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Blog Journey Controller
 *
 * Provides the marketing landing page and entry point for the magazine wizard.
 * Dispatches article sync in the background and redirects to the magazine wizard,
 * which handles the full flow: Setup → Categories → Articles → Review → Subdomain → Done.
 */
#[Route('/blog', name: 'blog_journey_')]
class BlogJourneyController extends AbstractController
{
    private const SESSION_JOURNEY_FLAG = 'blog_journey';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Landing page — marketing page that sells the idea.
     */
    #[Route('/start', name: 'landing')]
    public function landing(): Response
    {
        return $this->render('blog-journey/landing.html.twig');
    }

    /**
     * Entry point: log in (if needed), dispatch article sync,
     * then hand off to the magazine wizard.
     */
    #[Route('/setup', name: 'setup')]
    public function setup(Request $request): Response
    {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->render('blog-journey/setup-login.html.twig');
        }

        // Mark session so the magazine wizard knows the user came from the blog journey
        $request->getSession()->set(self::SESSION_JOURNEY_FLAG, true);

        // Dispatch background article sync from relays
        try {
            $key = new Key();
            $pubkeyHex = $key->convertToHex($this->getUser()->getUserIdentifier());

            if ($pubkeyHex) {
                $this->messageBus->dispatch(
                    new FetchAuthorArticlesMessage($pubkeyHex, 0)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to dispatch article sync in blog journey', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->redirectToRoute('mag_wizard_setup');
    }
}
