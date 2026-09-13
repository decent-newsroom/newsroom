<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller\Admin;

use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class PublicationAdminController
{
    public function __construct(
        private Environment $twig,
        private PublicationSettingsManager $settings,
        private HandlebarsRenderer $renderer,
        private EventReadGatewayInterface $events,
        private CsrfTokenManagerInterface $csrf,
        private LoggerInterface $logger,
    ) {}

    public function overview(PublicationContext $publication): Response
    {
        $title = $publication->dtag;
        $metadataAvailable = false;
        try {
            $event = $this->events->findByCoordinate($publication->coordinate);
            $dtag = null;
            foreach ($event?->tags ?? [] as $tag) {
                if (($tag[0] ?? null) === 'd') {
                    $dtag = $tag[1] ?? null;
                    break;
                }
            }
            if ($event !== null && $event->kind === 30040 && strtolower($event->pubkey) === $publication->ownerPubkey && $dtag === $publication->dtag) {
                $title = SiteConfig::fromEvent($event, $publication->coordinate, $publication->settings->theme)->title ?: $title;
                $metadataAvailable = true;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Publication admin metadata unavailable', ['coordinate' => $publication->coordinate, 'exception' => $e]);
        }
        return new Response($this->twig->render('@Unfold/admin/overview.html.twig', compact('publication', 'title', 'metadataAvailable')));
    }

    public function settings(Request $request, PublicationContext $publication): Response
    {
        $selectedTheme = $publication->settings->theme;
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $token = new CsrfToken('unfold_settings:' . $publication->coordinate, (string) $request->request->get('_token', ''));
            if (!$this->csrf->isTokenValid($token)) {
                throw new AccessDeniedHttpException();
            }
            $selectedTheme = (string) $request->request->get('theme', '');
            try {
                $this->settings->saveTheme($publication->coordinate, $selectedTheme);
                $request->getSession()->getFlashBag()->add('unfold_success', 'unfold_admin.saved');
                return new RedirectResponse($publication->adminPathPrefix . '/settings', 303);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
                $status = 422;
            } catch (\Throwable $e) {
                $this->logger->error('Publication settings save failed', ['coordinate' => $publication->coordinate, 'exception' => $e]);
                $error = 'unfold_setup.save_failed';
                $status = 503;
            }
        }
        return new Response($this->twig->render('@Unfold/admin/settings.html.twig', [
            'publication' => $publication,
            'themes' => $this->renderer->getAvailableThemes(),
            'selectedTheme' => $selectedTheme,
            'error' => $error,
        ]), $status);
    }
}
