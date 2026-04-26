<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Magazine;
use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\UpdateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\Update\UpdateAccessService;
use App\Service\UpdateProService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Updates Center — per-user update feed and subscription management.
 */
#[IsGranted('ROLE_USER')]
class UpdatesController extends AbstractController
{
    public function __construct(
        private readonly UpdateRepository $updateRepository,
        private readonly UpdateSubscriptionRepository $subscriptionRepository,
        private readonly UpdateAccessService $accessService,
        private readonly UpdateProService $proService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/updates', name: 'updates_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $raw = $this->updateRepository->findRecentForUser($user, 200);
        $subscriptions = $this->subscriptionRepository->findActiveForUser($user);

        // Opening the page marks all updates as seen and read.
        $this->updateRepository->markAllSeen($user);
        $this->updateRepository->markAllRead($user);

        // Collapse: for article updates (kind 30023), only keep the latest
        // per publication/author (identified by eventPubkey). Results are
        // already sorted newest-first so the first occurrence is the latest.
        $items = [];
        $seenPubkeys = [];
        foreach ($raw as $update) {
            if ($update->getEventKind() === 30023) {
                $key = $update->getEventPubkey();
                if (isset($seenPubkeys[$key])) {
                    continue;
                }
                $seenPubkeys[$key] = true;
            }
            $items[] = $update;
            if (count($items) >= 50) {
                break;
            }
        }

        return $this->render('updates/index.html.twig', [
            'updates' => $items,
            'subscriptions' => $subscriptions,
            'unread' => $this->updateRepository->countUnreadForUser($user),
            'isPro' => $this->accessService->isPro($user),
            'freeCap' => $this->accessService->getFreeCap(),
            'proSubscription' => $this->proService->getSubscription($user->getUserIdentifier()),
        ]);
    }

    #[Route('/updates/subscriptions', name: 'updates_subscriptions', methods: ['GET'])]
    public function subscriptions(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $subscriptions = $this->subscriptionRepository->findActiveForUser($user);

        // Resolve magazine titles for PUBLICATION subscriptions (coordinate → title map).
        $coordinateTitles = [];
        $magazineRepo = $this->em->getRepository(Magazine::class);
        foreach ($subscriptions as $sub) {
            if ($sub->getSourceType() !== UpdateSourceTypeEnum::PUBLICATION) {
                continue;
            }
            $parts = explode(':', $sub->getSourceValue(), 3);
            if (count($parts) !== 3) {
                continue;
            }
            [, $pubkey, $slug] = $parts;
            $magazine = $magazineRepo->findOneBy(['pubkey' => $pubkey, 'slug' => $slug]);
            if ($magazine?->getTitle() !== null) {
                $coordinateTitles[$sub->getSourceValue()] = $magazine->getTitle();
            }
        }

        return $this->render('updates/subscriptions.html.twig', [
            'subscriptions' => $subscriptions,
            'coordinateTitles' => $coordinateTitles,
            'isPro' => $this->accessService->isPro($user),
            'freeCap' => $this->accessService->getFreeCap(),
            'currentCount' => $this->subscriptionRepository->countActiveForUser($user),
            'proSubscription' => $this->proService->getSubscription($user->getUserIdentifier()),
        ]);
    }

    #[Route('/updates/subscriptions', name: 'updates_subscriptions_add', methods: ['POST'])]
    public function addSubscription(Request $request): Response
    {
        $redirectTo = $this->resolveRedirectPath($request) ?? $this->generateUrl('updates_subscriptions');

        if (!$this->isCsrfTokenValid('update-subscribe', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'updates.errors.invalidInput');
            return $this->redirect($redirectTo);
        }

        /** @var User $user */
        $user = $this->getUser();
        $raw = trim((string) $request->request->get('identifier', ''));
        if ($raw === '') {
            $this->addFlash('error', 'updates.errors.invalidInput');
            return $this->redirect($redirectTo);
        }

        try {
            [$type, $value, $label] = $this->parseIdentifier($raw);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', 'updates.errors.invalidInput');
            return $this->redirect($redirectTo);
        }

        // Paywall check
        $blockReason = $this->accessService->blockReason($user, $type);
        if ($blockReason !== null) {
            $this->addFlash('error', $blockReason);
            return $this->redirectToRoute('updates_pro_index');
        }

        if ($this->subscriptionRepository->findOneForUser($user, $type, $value) !== null) {
            $this->addFlash('error', 'updates.errors.alreadySubscribed');
            return $this->redirect($redirectTo);
        }

        $subscription = new UpdateSubscription($user, $type, $value, $label);
        $this->em->persist($subscription);
        $this->em->flush();

        return $this->redirect($redirectTo);
    }

    private function resolveRedirectPath(Request $request): ?string
    {
        $redirectTo = trim((string) $request->request->get('redirect_to', ''));

        if ($redirectTo === '') {
            return null;
        }

        if (!str_starts_with($redirectTo, '/') || str_starts_with($redirectTo, '//')) {
            return null;
        }

        return $redirectTo;
    }

    #[Route('/updates/subscriptions/{id}', name: 'updates_subscriptions_remove', methods: ['POST', 'DELETE'])]
    public function removeSubscription(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('update-unsubscribe', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'updates.errors.invalidInput');
            return $this->redirectToRoute('updates_subscriptions');
        }

        /** @var User $user */
        $user = $this->getUser();
        $sub = $this->subscriptionRepository->find($id);
        if ($sub === null || $sub->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($sub);
        $this->em->flush();

        return $this->redirectToRoute('updates_subscriptions');
    }

    // ----- JSON API for the bell/toast -----

    #[Route('/api/updates/unread-count', name: 'api_updates_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse([
            'unread' => $this->updateRepository->countUnreadForUser($user),
            'unseen' => $this->updateRepository->countUnseenForUser($user),
        ]);
    }

    #[Route('/api/updates/{id}/read', name: 'api_updates_mark_read', methods: ['POST'])]
    public function markRead(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $n = $this->updateRepository->findForUser($user, $id);
        if ($n === null) {
            return new JsonResponse(['ok' => false], 404);
        }
        $n->markRead();
        $this->em->flush();
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/updates/mark-all-seen', name: 'api_updates_mark_all_seen', methods: ['POST'])]
    public function markAllSeen(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $count = $this->updateRepository->markAllSeen($user);
        return new JsonResponse(['ok' => true, 'updated' => $count]);
    }

    /**
     * Parse a user-supplied identifier into a (type, value, label) tuple.
     *
     * Accepts:
     *  - raw hex pubkey (64 chars)  → NPUB
     *  - `npub1…` / `nostr:npub1…`  → NPUB
     *  - `naddr1…` / `nostr:naddr1…` → PUBLICATION (if kind=30040) or NIP51_SET (if a supported NIP-51 set kind)
     *  - raw `kind:pubkey:d` coordinate → PUBLICATION or NIP51_SET depending on kind
     *
     * @return array{0: UpdateSourceTypeEnum, 1: string, 2: ?string}
     */
    private function parseIdentifier(string $raw): array
    {
        $raw = NostrKeyUtil::normalizeNostrIdentifier($raw);

        // Hex pubkey
        if (NostrKeyUtil::isHexPubkey($raw)) {
            return [UpdateSourceTypeEnum::NPUB, $raw, null];
        }

        // npub1…
        if (NostrKeyUtil::isNpub($raw)) {
            return [UpdateSourceTypeEnum::NPUB, NostrKeyUtil::npubToHex($raw), $raw];
        }

        // naddr1…
        if (str_starts_with($raw, 'naddr1')) {
            $decoded = (new Nip19Helper())->decode($raw);
            if (!isset($decoded['kind'], $decoded['author'], $decoded['identifier'])) {
                throw new \InvalidArgumentException('Malformed naddr');
            }
            $coord = $decoded['kind'] . ':' . $decoded['author'] . ':' . $decoded['identifier'];
            return [$this->typeForCoordinate((int) $decoded['kind']), $coord, $raw];
        }

        // Raw coordinate `kind:pubkey:d`
        if (preg_match('/^(\d+):([0-9a-f]{64}):(.*)$/', $raw, $m)) {
            return [$this->typeForCoordinate((int) $m[1]), $raw, null];
        }

        throw new \InvalidArgumentException('Unrecognized identifier');
    }

    private function typeForCoordinate(int $kind): UpdateSourceTypeEnum
    {
        if ($kind === 30040) {
            return UpdateSourceTypeEnum::PUBLICATION;
        }
        // Any other supported set kind goes under NIP51_SET.
        $setKinds = [3, 10000, 10015, 30003, 30004, 30005, 30015, 39089];
        if (in_array($kind, $setKinds, true)) {
            return UpdateSourceTypeEnum::NIP51_SET;
        }
        throw new \InvalidArgumentException('Unsupported kind for update subscription');
    }
}

