<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller\Admin;

use DecentNewsroom\UnfoldBundle\Admin\PublicationOnboardingContext;
use DecentNewsroom\UnfoldBundle\Config\PublicationDraft;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Contract\PublicationDraftStoreInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationIndexConflictException;
use DecentNewsroom\UnfoldBundle\Contract\SignedPublicationIndexPublisherInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private SignedPublicationIndexPublisherInterface $publisher,
        private PublicationSettingsManager $settings,
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

    public function prepareRoot(Request $request, PublicationOnboardingContext $onboarding): JsonResponse
    {
        $data = $this->jsonData($request);
        if ($data === null || !$this->validCsrf($data, $onboarding)) {
            return new JsonResponse(['error' => 'Invalid request.'], 403);
        }

        try {
            $draft = $this->draft($data, $onboarding);

            return new JsonResponse(['event' => $this->rootEvent($draft)]);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Publication draft is unavailable.'], 422);
        }
    }

    public function commitRoot(Request $request, PublicationOnboardingContext $onboarding): JsonResponse
    {
        $data = $this->jsonData($request);
        if ($data === null || !$this->validCsrf($data, $onboarding)) {
            return new JsonResponse(['error' => 'Invalid request.'], 403);
        }

        try {
            $draft = $this->draft($data, $onboarding);
            $signed = $data['event'] ?? null;
            if (!is_array($signed) || !$this->matchesPreparedRoot($signed, $draft)) {
                return new JsonResponse(['error' => 'Signed event differs from the publication draft.'], 422);
            }

            $result = $this->publisher->publishRoot($signed, $draft->rootCoordinate());
            $this->settings->saveTheme($draft->rootCoordinate(), $draft->theme);
            $this->drafts->migrateProvisionalToCanonical($draft);

            return new JsonResponse([
                'ok' => true,
                'event_id' => $result['event_id'],
                'published' => $result['published'],
                'relay_results' => $result['relay_results'],
                'admin_url' => '/mag/' . rawurlencode($draft->dtag) . '/admin',
            ]);
        } catch (PublicationIndexConflictException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Publication draft is unavailable.'], 422);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'Publication was saved, but local setup could not be finalized.'], 503);
        }
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

    /** @param array<string, mixed> $data */
    private function draft(array $data, PublicationOnboardingContext $onboarding): PublicationDraft
    {
        $dtag = $data['dtag'] ?? null;
        if (!is_string($dtag)) {
            throw new \InvalidArgumentException();
        }
        $draft = $this->drafts->findByProvisionalKey($onboarding->ownerPubkey . ':' . $dtag);
        if ($draft === null || $draft->ownerPubkey !== $onboarding->ownerPubkey) {
            throw new \InvalidArgumentException();
        }

        return $draft;
    }

    /** @return array<string, mixed>|null */
    private function jsonData(Request $request): ?array
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function validCsrf(array $data, PublicationOnboardingContext $onboarding): bool
    {
        return is_string($data['_token'] ?? null) && $this->csrf->isTokenValid(
            new CsrfToken('unfold_onboarding:' . $onboarding->ownerPubkey, $data['_token']),
        );
    }

    /** @return array{pubkey: string, kind: int, created_at: int, tags: list<list<string>>, content: string} */
    private function rootEvent(PublicationDraft $draft): array
    {
        $tags = [['d', $draft->dtag], ['type', 'magazine'], ['alt', 'This is a publication viewable on Decent Newsroom.']];
        if ($draft->title !== '') {
            $tags[] = ['title', $draft->title];
        }
        if ($draft->summary !== '') {
            $tags[] = ['summary', $draft->summary];
        }
        if ($draft->imageUrl !== null) {
            $tags[] = ['image', $draft->imageUrl];
        }
        if ($draft->language !== null) {
            $tags[] = ['L', 'ISO-639-1'];
            $tags[] = ['l', $draft->language, 'ISO-639-1'];
        }
        foreach ($draft->tags as $tag) {
            $tags[] = ['t', $tag];
        }

        return ['pubkey' => $draft->ownerPubkey, 'kind' => 30040, 'created_at' => time(), 'tags' => $tags, 'content' => ''];
    }

    /** @param array<string, mixed> $signed */
    private function matchesPreparedRoot(array $signed, PublicationDraft $draft): bool
    {
        $createdAt = $signed['created_at'] ?? null;

        return ($signed['pubkey'] ?? null) === $draft->ownerPubkey
            && ($signed['kind'] ?? null) === 30040
            && ($signed['tags'] ?? null) === $this->rootEvent($draft)['tags']
            && ($signed['content'] ?? null) === ''
            && is_int($createdAt)
            && $createdAt >= time() - 300 && $createdAt <= time() + 300
            && is_string($signed['id'] ?? null)
            && is_string($signed['sig'] ?? null);
    }
}
