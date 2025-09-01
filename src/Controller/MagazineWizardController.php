<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CategoryDraft;
use App\Dto\MagazineDraft;
use App\Form\CategoryArticlesType;
use App\Form\MagazineSetupType;
use App\Service\RedisCacheService;
use Psr\Cache\CacheItemPoolInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use swentel\nostr\Key\Key;

class MagazineWizardController extends AbstractController
{
    private const SESSION_KEY = 'mag_wizard';

    #[Route('/magazine/wizard/setup', name: 'mag_wizard_setup')]
    public function setup(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            $draft = new MagazineDraft();
            $draft->categories = [new CategoryDraft()];
        }

        $form = $this->createForm(MagazineSetupType::class, $draft);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $draft = $form->getData();
            // Slug generation with a short random suffix
            if (!$draft->slug) {
                $draft->slug = $this->slugifyWithRandom($draft->title);
            }
            foreach ($draft->categories as $cat) {
                if (!$cat->slug) {
                    $cat->slug = $this->slugifyWithRandom($cat->title);
                }
            }
            $this->saveDraft($request, $draft);
            return $this->redirectToRoute('mag_wizard_articles');
        }

        return $this->render('magazine/magazine_setup.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/magazine/wizard/articles', name: 'mag_wizard_articles')]
    public function articles(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('mag_wizard_setup');
        }

        // Build a form as a collection of CategoryArticlesType
        $formBuilder = $this->createFormBuilder($draft);
        $formBuilder->add('categories', \Symfony\Component\Form\Extension\Core\Type\CollectionType::class, [
            'entry_type' => CategoryArticlesType::class,
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
            'label' => false,
        ]);
        $form = $formBuilder->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveDraft($request, $form->getData());
            return $this->redirectToRoute('mag_wizard_review');
        }

        return $this->render('magazine/magazine_articles.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/magazine/wizard/review', name: 'mag_wizard_review')]
    public function review(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('mag_wizard_setup');
        }

        // Build event skeletons (without pubkey/sig/id); created_at client can adjust
        $categoryEvents = [];
        foreach ($draft->categories as $cat) {
            $tags = [];
            $tags[] = ['d', $cat->slug];
            if ($cat->title) { $tags[] = ['title', $cat->title]; }
            if ($cat->summary) { $tags[] = ['summary', $cat->summary]; }
            foreach ($cat->tags as $t) { $tags[] = ['t', $t]; }
            foreach ($cat->articles as $a) {
                if (is_string($a) && $a !== '') { $tags[] = ['a', $a]; }
            }
            $categoryEvents[] = [
                'kind' => 30040,
                'created_at' => time(),
                'tags' => $tags,
                'content' => '',
            ];
        }

        // Determine current user's pubkey (hex) from their npub (user identifier)
        $pubkeyHex = null;
        $user = $this->getUser();
        if ($user && method_exists($user, 'getUserIdentifier')) {
            try {
                $key = new Key();
                $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
            } catch (\Throwable $e) {
                $pubkeyHex = null;
            }
        }

        $magTags = [];
        $magTags[] = ['d', $draft->slug];
        if ($draft->title) { $magTags[] = ['title', $draft->title]; }
        if ($draft->summary) { $magTags[] = ['summary', $draft->summary]; }
        if ($draft->imageUrl) { $magTags[] = ['image', $draft->imageUrl]; }
        if ($draft->language) { $magTags[] = ['l', $draft->language]; }
        foreach ($draft->tags as $t) { $magTags[] = ['t', $t]; }

        // If we know the user's pubkey, include all category coordinates as 'a' tags now
        if ($pubkeyHex) {
            foreach ($draft->categories as $cat) {
                if ($cat->slug) {
                    $magTags[] = ['a', sprintf('30040:%s:%s', $pubkeyHex, $cat->slug)];
                }
            }
        }

        $magazineEvent = [
            'kind' => 30040,
            'created_at' => time(),
            'tags' => $magTags,
            'content' => '',
        ];

        return $this->render('magazine/magazine_review.html.twig', [
            'draft' => $draft,
            'categoryEventsJson' => json_encode($categoryEvents, JSON_UNESCAPED_SLASHES),
            'magazineEventJson' => json_encode($magazineEvent, JSON_UNESCAPED_SLASHES),
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('nostr_publish')->getValue(),
        ]);
    }

    #[Route('/api/index/publish', name: 'api-index-publish', methods: ['POST'])]
    public function publishIndexEvent(
        Request $request,
        CacheItemPoolInterface $redisCache,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {
        // Verify CSRF token
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('nostr_publish', $csrfToken))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['event'])) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $signedEvent = $data['event'];

        // Convert array to swentel Event and verify
        $eventObj = new Event();
        $eventObj->setId($signedEvent['id'] ?? '');
        $eventObj->setPublicKey($signedEvent['pubkey'] ?? '');
        $eventObj->setCreatedAt($signedEvent['created_at'] ?? time());
        $eventObj->setKind($signedEvent['kind'] ?? 30040);
        $eventObj->setTags($signedEvent['tags'] ?? []);
        $eventObj->setContent($signedEvent['content'] ?? '');
        $eventObj->setSignature($signedEvent['sig'] ?? '');

        if (!$eventObj->verify()) {
            return new JsonResponse(['error' => 'Verification failed'], 400);
        }

        // Extract slug from 'd' tag
        $slug = null;
        foreach ($signedEvent['tags'] as $tag) {
            if (isset($tag[0]) && $tag[0] === 'd' && isset($tag[1])) {
                $slug = $tag[1];
                break;
            }
        }
        if (!$slug) {
            return new JsonResponse(['error' => 'Missing d tag/slug'], 400);
        }

        // Save to Redis under magazine-<slug>
        try {
            $key = 'magazine-' . $slug;
            $item = $redisCache->getItem($key);
            $item->set($eventObj);
            $redisCache->save($item);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Redis error'], 500);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/magazine/wizard/cancel', name: 'mag_wizard_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        $this->clearDraft($request);
        $this->addFlash('info', 'Magazine setup canceled.');
        return $this->redirectToRoute('home');
    }

    private function getDraft(Request $request): ?MagazineDraft
    {
        $data = $request->getSession()->get(self::SESSION_KEY);
        return $data instanceof MagazineDraft ? $data : null;
    }

    private function saveDraft(Request $request, MagazineDraft $draft): void
    {
        $request->getSession()->set(self::SESSION_KEY, $draft);
    }

    private function clearDraft(Request $request): void
    {
        $request->getSession()->remove(self::SESSION_KEY);
    }

    private function slugifyWithRandom(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) ?? '');
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
        $rand = substr(bin2hex(random_bytes(4)), 0, 6);
        return $slug !== '' ? ($slug . '-' . $rand) : $rand;
    }
}
