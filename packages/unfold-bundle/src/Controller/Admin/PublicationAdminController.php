<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller\Admin;

use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Config\AboutArticleReference;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
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
        $footerLinks = $publication->settings->footerLinks;
        $currentAboutArticle = $this->currentAboutArticle($publication);
        $aboutArticle = $currentAboutArticle ?? '';
        $aboutRelayHints = $aboutArticle !== '' && $aboutArticle === $publication->settings->aboutArticleCoordinate
            ? $publication->settings->aboutRelayHints : [];

        // A draft may be handed from the hosted admin to the main-domain signer.
        // It changes only this form's display, never the persisted selection.
        if ($request->isMethod('GET') && $request->query->has('about_article')) {
            $draft = $request->query->all()['about_article'] ?? null;
            if (is_string($draft)) {
                if (trim($draft) === '') {
                    $aboutArticle = '';
                    $aboutRelayHints = [];
                } else {
                    try {
                        $reference = AboutArticleReference::fromInput($draft);
                        $aboutArticle = trim($draft);
                        $aboutRelayHints = $reference->relayHints;
                    } catch (\InvalidArgumentException) {
                        // Keep the saved or conventional selection for an invalid draft.
                    }
                }
            }
        }

        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $token = new CsrfToken('unfold_settings:' . $publication->coordinate, (string) $request->request->get('_token', ''));
            if (!$this->csrf->isTokenValid($token)) {
                throw new AccessDeniedHttpException();
            }
            $selectedTheme = (string) $request->request->get('theme', '');
            try {
                $footerLinks = $this->submittedFooterLinks($request);
                $form = $request->request->all();
                $aboutCoordinateToSave = null;
                $aboutHintsToSave = [];
                $updateAboutRelayHints = false;
                if (array_key_exists('about_article', $form)) {
                    if (!is_string($form['about_article'])) {
                        throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
                    }
                    $aboutArticle = $form['about_article'];
                    $reference = trim($aboutArticle) === '' ? null : AboutArticleReference::fromInput($aboutArticle);
                    if ($reference?->coordinate !== $currentAboutArticle) {
                        throw new \InvalidArgumentException('unfold_admin.about_article_signature_required');
                    }
                    if ($reference !== null) {
                        $aboutArticle = $reference->coordinate;
                        $aboutRelayHints = $reference->relayHints !== []
                            ? $reference->relayHints : $publication->settings->aboutRelayHints;
                        if ($reference->relayHints !== []) {
                            $aboutCoordinateToSave = $reference->coordinate;
                            $aboutHintsToSave = $reference->relayHints;
                            $updateAboutRelayHints = true;
                        }
                    }
                }

                // The signed flow owns coordinate changes. New relay hints for
                // the same coordinate can be saved with theme and footer links.
                $this->settings->savePresentation(
                    $publication->coordinate,
                    $selectedTheme,
                    $footerLinks,
                    $aboutCoordinateToSave,
                    $aboutHintsToSave,
                    $updateAboutRelayHints,
                );
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
            'footerLinks' => $footerLinks,
            'aboutArticle' => $aboutArticle,
            'currentAboutArticle' => $currentAboutArticle ?? '',
            'aboutArticleTitle' => $this->aboutArticleTitle($aboutArticle, $aboutRelayHints),
            'error' => $error,
        ]), $status);
    }

    private function currentAboutArticle(PublicationContext $publication): ?string
    {
        if ($publication->settings->aboutArticleCoordinate !== null) {
            return $publication->settings->aboutArticleCoordinate;
        }

        try {
            $event = $this->events->findByCoordinate($publication->coordinate);
            if (!$this->matchesCoordinate($event, $publication->coordinate)) {
                return null;
            }

            $references = array_values(array_unique(
                SiteConfig::fromEvent($event, $publication->coordinate)->rootArticleCoordinates,
            ));
            return count($references) === 1 ? $references[0] : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Publication About reference unavailable', [
                'coordinate' => $publication->coordinate,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /** @param list<string> $relayHints */
    private function aboutArticleTitle(string $coordinate, array $relayHints): ?string
    {
        if ($coordinate === '') {
            return null;
        }

        try {
            $reference = AboutArticleReference::fromInput($coordinate);
            $event = $this->events->findByCoordinate($reference->coordinate, $relayHints);
            if (!$this->matchesCoordinate($event, $reference->coordinate)) {
                return null;
            }
            foreach ($event->tags as $tag) {
                if (($tag[0] ?? null) === 'title' && trim($tag[1] ?? '') !== '') {
                    return trim($tag[1]);
                }
            }
        } catch (\InvalidArgumentException) {
            // Keep the coordinate visible if title metadata is unavailable.
        } catch (\Throwable $e) {
            $this->logger->warning('Publication About article title unavailable', [
                'coordinate' => $coordinate,
                'exception' => $e,
            ]);
        }

        return null;
    }

    private function matchesCoordinate(?NostrEvent $event, string $coordinate): bool
    {
        if ($event === null) {
            return false;
        }
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || $event->kind !== (int) $parts[0]
            || strtolower($event->pubkey) !== strtolower($parts[1])) {
            return false;
        }
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 'd') {
                return ($tag[1] ?? null) === $parts[2];
            }
        }
        return false;
    }

    /** @return list<array{label: string, url: string}> */
    private function submittedFooterLinks(Request $request): array
    {
        $submitted = $request->request->all()['footer_links'] ?? [];
        if (!is_array($submitted) || count($submitted) > 5) {
            throw new \InvalidArgumentException('unfold_setup.invalid_footer_links');
        }

        $links = [];
        foreach ($submitted as $row) {
            if (!is_array($row) || !is_string($row['label'] ?? null) || !is_string($row['url'] ?? null)) {
                throw new \InvalidArgumentException('unfold_setup.invalid_footer_links');
            }
            if (trim($row['label']) === '' && trim($row['url']) === '') {
                continue;
            }
            $links[] = ['label' => $row['label'], 'url' => $row['url']];
        }

        return $links;
    }
}
