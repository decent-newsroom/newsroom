<?php

namespace App\UnfoldBundle\Controller;

use App\UnfoldBundle\Http\HostResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo controller for testing Unfold subdomain routing
 * Access via: http://support.localhost/demo
 */
class DemoController extends AbstractController
{
    public function __construct(
        private readonly HostResolver $hostResolver,
    ) {}

    #[Route('/demo', name: 'unfold_demo')]
    public function index(Request $request): Response
    {
        $host = $request->getHost();

        // First check if the request listener already resolved it
        $unfoldSite = $request->attributes->get('_unfold_site');

        // Fall back to HostResolver if not pre-resolved (for direct access)
        if ($unfoldSite === null) {
            $unfoldSite = $this->hostResolver->resolve();
        }

        return $this->render('@Unfold/demo.html.twig', [
            'host' => $host,
            'subdomain' => $unfoldSite?->getSubdomain() ?? 'not found',
            'naddr' => $unfoldSite?->getNaddr(),
            'siteFound' => $unfoldSite !== null,
        ]);
    }
}
