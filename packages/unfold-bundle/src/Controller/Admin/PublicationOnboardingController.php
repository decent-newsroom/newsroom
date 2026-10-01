<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller\Admin;

use DecentNewsroom\UnfoldBundle\Admin\PublicationOnboardingContext;
use DecentNewsroom\UnfoldBundle\Config\PublicationDraft;
use DecentNewsroom\UnfoldBundle\Contract\PublicationDraftStoreInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class PublicationOnboardingController
{
    public function __construct(
        private Environment $twig,
        private PublicationDraftStoreInterface $drafts,
        private HandlebarsRenderer $renderer,
        private CsrfTokenManagerInterface $csrf,
    ) {}

    public function basics(Request $request, PublicationOnboardingContext $onboarding): Response
    {
        $dtag = trim((string) $request->query->get('dtag', $request->request->get('dtag', '')));
        $draft = null;
        $error = null;

        if ($dtag !== '') {
            try {
                $draft = $this->drafts->findByProvisionalKey($onboarding->ownerPubkey . ':' . $dtag);
            } catch (\InvalidArgumentException) {
                $error = 'unfold_onboarding.invalid_dtag';
            }
        }

        if ($request->isMethod('POST') && $error === null) {
            $token = new CsrfToken('unfold_onboarding:' . $onboarding->ownerPubkey, (string) $request->request->get('_token', ''));
            if (!$this->csrf->isTokenValid($token)) {
                throw new AccessDeniedHttpException();
            }

            try {
                $draft = PublicationDraft::create(
                    $onboarding->ownerPubkey,
                    $dtag,
                    (string) $request->request->get('title', ''),
                    (string) $request->request->get('summary', ''),
                    $this->nullableString($request, 'image_url'),
                    $this->nullableString($request, 'language'),
                    $this->submittedTags($request),
                    (string) $request->request->get('theme', 'default'),
                );
                $this->drafts->save($draft);

                return new RedirectResponse('/magazine/onboarding?dtag=' . rawurlencode($draft->dtag), 303);
            } catch (\InvalidArgumentException) {
                $error = 'unfold_onboarding.invalid_draft';
            }
        }

        return new Response($this->twig->render('@Unfold/onboarding/basics.html.twig', [
            'onboarding' => $onboarding,
            'draft' => $draft,
            'dtag' => $dtag,
            'themes' => $this->renderer->getAvailableThemes(),
            'error' => $error,
        ]));
    }

    public function discard(Request $request, PublicationOnboardingContext $onboarding): RedirectResponse
    {
        $token = new CsrfToken('unfold_onboarding:' . $onboarding->ownerPubkey, (string) $request->request->get('_token', ''));
        if (!$this->csrf->isTokenValid($token)) {
            throw new AccessDeniedHttpException();
        }

        $dtag = (string) $request->request->get('dtag', '');
        try {
            $this->drafts->discardByProvisionalKey($onboarding->ownerPubkey . ':' . $dtag);
        } catch (\InvalidArgumentException) {
            throw new AccessDeniedHttpException();
        }

        return new RedirectResponse('/magazine/onboarding', 303);
    }

    private function nullableString(Request $request, string $name): ?string
    {
        $value = $request->request->get($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return list<string> */
    private function submittedTags(Request $request): array
    {
        $value = $request->request->get('tags', '');
        if (!is_string($value)) {
            throw new \InvalidArgumentException();
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $tag): bool => $tag !== ''));
    }
}
