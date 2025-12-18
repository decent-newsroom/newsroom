<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CategoryDraft;
use App\Dto\MagazineDraft;
use App\Enum\KindsEnum;
use App\Form\CategoryArticlesType;
use App\Form\MagazineSetupType;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use swentel\nostr\Key\Key;
use Redis as RedisClient;

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
    public function review(Request $request, NzineRepository $nzineRepository): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('mag_wizard_setup');
        }

        // Check if this slug belongs to an NZine (which has a bot)
        $nzine = null;
        $isNzineEdit = false;
        if ($draft->slug) {
            $nzine = $nzineRepository->findOneBy(['slug' => $draft->slug]);
            if ($nzine && $nzine->getNzineBot()) {
                $isNzineEdit = true;
            }
        }

        // Build event skeletons (without pubkey/sig/id); created_at client can adjust
        $categoryEvents = [];
        foreach ($draft->categories as $cat) {
            $tags = [];
            $tags[] = ['d', $cat->slug];
            $tags[] = ['type', 'magazine'];
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
        // For NZine edits, use the NZine's npub instead
        $pubkeyHex = null;
        if ($isNzineEdit && $nzine) {
            try {
                $key = new Key();
                $pubkeyHex = $key->convertToHex($nzine->getNpub());
            } catch (\Throwable $e) {
                $pubkeyHex = null;
            }
        } else {
            $user = $this->getUser();
            if ($user && method_exists($user, 'getUserIdentifier')) {
                try {
                    $key = new Key();
                    $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
                } catch (\Throwable $e) {
                    $pubkeyHex = null;
                }
            }
        }

        $magTags = [];
        $magTags[] = ['d', $draft->slug];
        $magTags[] = ['type', 'magazine'];
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
            'isNzineEdit' => $isNzineEdit,
            'nzineSlug' => $isNzineEdit ? $draft->slug : null,
        ]);
    }

    #[Route('/api/index/publish', name: 'api-index-publish', methods: ['POST'])]
    public function publishIndexEvent(
        Request                   $request,
        CacheItemPoolInterface    $appCache,
        CsrfTokenManagerInterface $csrfTokenManager,
        RedisClient               $redis,
        EntityManagerInterface    $entityManager
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
        $eventObj->setKind($signedEvent['kind'] ?? KindsEnum::PUBLICATION_INDEX->value);
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
            $item = $appCache->getItem($key);
            $item->set($eventObj);
            $appCache->save($item);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Redis error'], 500);
        }

        // If the event is a top-level magazine index (references 30040 categories), record slug in a set for admin listing
        try {
            $isTopLevelMagazine = false;
            foreach ($signedEvent['tags'] as $tag) {
                if (($tag[0] ?? null) === 'a' && isset($tag[1]) && str_starts_with((string)$tag[1], '30040:')) {
                    $isTopLevelMagazine = true; break;
                }
            }
            if ($isTopLevelMagazine) {
                $redis->sAdd('magazine_slugs', $slug);
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Save to persistence as Event entity
        // Map swentel Event to Event entity, it's always a new event
        $event = new \App\Entity\Event();
        $event->setId($eventObj->getId());
        $event->setPubkey($eventObj->getPublicKey());
        $event->setCreatedAt($eventObj->getCreatedAt());
        $event->setKind($eventObj->getKind());
        $event->setTags($eventObj->getTags());
        $event->setContent($eventObj->getContent());
        $event->setSig($eventObj->getSignature());
        // Persist
        $entityManager->persist($event);
        $entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/nzine-index/publish', name: 'api-nzine-index-publish', methods: ['POST'])]
    public function publishNzineIndexEvent(
        Request                   $request,
        CsrfTokenManagerInterface $csrfTokenManager,
        NzineRepository           $nzineRepository,
        EncryptionService         $encryptionService,
        CacheItemPoolInterface    $appCache,
        RedisClient               $redis,
        EntityManagerInterface    $entityManager
    ): JsonResponse {
        // Verify CSRF token
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('nostr_publish', $csrfToken))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['nzineSlug']) || !isset($data['categoryEvents']) || !isset($data['magazineEvent'])) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $nzineSlug = $data['nzineSlug'];
        $categorySkeletons = $data['categoryEvents'];
        $magazineSkeleton = $data['magazineEvent'];

        // Load the NZine entity
        $nzine = $nzineRepository->findOneBy(['slug' => $nzineSlug]);
        if (!$nzine || !$nzine->getNzineBot()) {
            return new JsonResponse(['error' => 'NZine not found or no bot configured'], 404);
        }

        // Get the bot's nsec for signing
        $bot = $nzine->getNzineBot();
        $bot->setEncryptionService($encryptionService);
        $nsec = $bot->getNsec();
        if (!$nsec) {
            return new JsonResponse(['error' => 'Bot credentials not available'], 500);
        }

        $key = new Key();
        $pubkeyHex = $key->getPublicKey($nsec);
        $signer = new Sign();

        $categoryCoordinates = [];

        try {
            // 1) Sign and publish each category event
            foreach ($categorySkeletons as $catSkeleton) {
                $catEvent = new Event();
                $catEvent->setKind($catSkeleton['kind'] ?? 30040);
                $catEvent->setCreatedAt($catSkeleton['created_at'] ?? time());
                $catEvent->setTags($catSkeleton['tags'] ?? []);
                $catEvent->setContent($catSkeleton['content'] ?? '');

                // Sign with bot's nsec
                $signer->signEvent($catEvent, $nsec);

                // Extract slug from d tag
                $slug = null;
                foreach ($catEvent->getTags() as $tag) {
                    if (($tag[0] ?? null) === 'd' && isset($tag[1])) {
                        $slug = $tag[1];
                        break;
                    }
                }
                if (!$slug) {
                    return new JsonResponse(['error' => 'Category missing d tag'], 400);
                }

                // Save to Redis
                $cacheKey = 'magazine-' . $slug;
                $item = $appCache->getItem($cacheKey);
                $item->set($catEvent);
                $appCache->save($item);

                // Save to database
                $eventEntity = new \App\Entity\Event();
                $eventEntity->setId($catEvent->getId());
                $eventEntity->setPubkey($catEvent->getPublicKey());
                $eventEntity->setCreatedAt($catEvent->getCreatedAt());
                $eventEntity->setKind($catEvent->getKind());
                $eventEntity->setTags($catEvent->getTags());
                $eventEntity->setContent($catEvent->getContent());
                $eventEntity->setSig($catEvent->getSignature());
                $entityManager->persist($eventEntity);

                // Build coordinate
                $categoryCoordinates[] = sprintf('30040:%s:%s', $pubkeyHex, $slug);
            }

            // 2) Build and sign the magazine event with category references
            $magEvent = new Event();
            $magEvent->setKind($magazineSkeleton['kind'] ?? 30040);
            $magEvent->setCreatedAt($magazineSkeleton['created_at'] ?? time());

            // Remove any existing 'a' tags and add the new category coordinates
            $magTags = array_filter($magazineSkeleton['tags'] ?? [], fn($t) => ($t[0] ?? null) !== 'a');
            foreach ($categoryCoordinates as $coord) {
                $magTags[] = ['a', $coord];
            }
            $magEvent->setTags($magTags);
            $magEvent->setContent($magazineSkeleton['content'] ?? '');

            // Sign with bot's nsec
            $signer->signEvent($magEvent, $nsec);

            // Extract magazine slug
            $magSlug = null;
            foreach ($magEvent->getTags() as $tag) {
                if (($tag[0] ?? null) === 'd' && isset($tag[1])) {
                    $magSlug = $tag[1];
                    break;
                }
            }
            if (!$magSlug) {
                return new JsonResponse(['error' => 'Magazine missing d tag'], 400);
            }

            // Save magazine to Redis
            $cacheKey = 'magazine-' . $magSlug;
            $item = $appCache->getItem($cacheKey);
            $item->set($magEvent);
            $appCache->save($item);

            // Save magazine to database
            $magEventEntity = new \App\Entity\Event();
            $magEventEntity->setId($magEvent->getId());
            $magEventEntity->setPubkey($magEvent->getPublicKey());
            $magEventEntity->setCreatedAt($magEvent->getCreatedAt());
            $magEventEntity->setKind($magEvent->getKind());
            $magEventEntity->setTags($magEvent->getTags());
            $magEventEntity->setContent($magEvent->getContent());
            $magEventEntity->setSig($magEvent->getSignature());
            $entityManager->persist($magEventEntity);

            // Record slug in Redis set for admin listing
            $redis->sAdd('magazine_slugs', $magSlug);

            $entityManager->flush();

            return new JsonResponse(['ok' => true, 'message' => 'NZine magazine updated successfully']);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/magazine/wizard/cancel', name: 'mag_wizard_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        $this->clearDraft($request);
        $this->addFlash('info', 'Magazine setup canceled.');
        return $this->redirectToRoute('home');
    }

    #[Route('/magazine/wizard/edit/{slug}', name: 'mag_wizard_edit')]
    public function editStart(string $slug, EntityManagerInterface $entityManager, Request $request): Response
    {
        // Load magazine event from database
        $sql = "SELECT e.* FROM event e
                WHERE e.tags::jsonb @> ?::jsonb
                LIMIT 1";

        $conn = $entityManager->getConnection();
        $result = $conn->executeQuery($sql, [
            json_encode([['d', $slug]])
        ]);

        $magEventData = $result->fetchAssociative();

        if ($magEventData === false) {
            throw $this->createNotFoundException('Magazine not found');
        }

        $tags = json_decode($magEventData['tags'], true);

        $draft = new \App\Dto\MagazineDraft();
        $draft->slug = $slug;
        $draft->title = $this->getTagValue($tags, 'title') ?? '';
        $draft->summary = $this->getTagValue($tags, 'summary') ?? '';
        $draft->imageUrl = $this->getTagValue($tags, 'image') ?? '';
        $draft->language = $this->getTagValue($tags, 'l');
        $draft->tags = $this->getAllTagValues($tags, 't');
        $draft->categories = [];

        // For each category coordinate (30040:pubkey:slug), load its index and map
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === 'a' && isset($t[1]) && str_starts_with((string)$t[1], '30040:')) {
                $parts = explode(':', (string)$t[1], 3);
                if (count($parts) !== 3) { continue; }
                $catSlug = $parts[2];

                // Query database for category event
                $catResult = $conn->executeQuery($sql, [
                    json_encode([['d', $catSlug]])
                ]);
                $catEventData = $catResult->fetchAssociative();

                if ($catEventData === false) { continue; }

                $ctags = json_decode($catEventData['tags'], true);
                $cat = new \App\Dto\CategoryDraft();
                $cat->slug = $catSlug;
                $cat->title = $this->getTagValue($ctags, 'title') ?? '';
                $cat->summary = $this->getTagValue($ctags, 'summary') ?? '';
                $cat->tags = $this->getAllTagValues($ctags, 't');
                $cat->articles = [];
                foreach ($ctags as $ct) {
                    if (is_array($ct) && ($ct[0] ?? null) === 'a' && isset($ct[1])) {
                        $cat->articles[] = (string)$ct[1];
                    }
                }
                $draft->categories[] = $cat;
            }
        }

        $this->saveDraft($request, $draft);
        return $this->redirectToRoute('mag_wizard_setup');
    }

    private function getTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === $name && isset($t[1])) {
                return (string)$t[1];
            }
        }
        return null;
    }

    private function getAllTagValues(array $tags, string $name): array
    {
        $out = [];
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === $name && isset($t[1])) {
                $out[] = (string)$t[1];
            }
        }
        return $out;
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
