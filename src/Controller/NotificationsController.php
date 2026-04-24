<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Magazine;
use App\Entity\NotificationSubscription;
use App\Entity\User;
use App\Enum\NotificationSourceTypeEnum;
use App\Repository\NotificationRepository;
use App\Repository\NotificationSubscriptionRepository;
use App\Service\Notification\NotificationAccessService;
use App\Service\NotificationProService;
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
 * Notifications Center — per-user notification feed and subscription management.
 */
#[IsGranted('ROLE_USER')]
class NotificationsController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationSubscriptionRepository $subscriptionRepository,
        private readonly NotificationAccessService $accessService,
        private readonly NotificationProService $proService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/notifications', name: 'notifications_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $items = $this->notificationRepository->findRecentForUser($user, 50);
        $subscriptions = $this->subscriptionRepository->findActiveForUser($user);

        // Opening the page clears the "unseen" badge but leaves per-item read state.
        $this->notificationRepository->markAllSeen($user);

        return $this->render('notifications/index.html.twig', [
            'notifications' => $items,
            'subscriptions' => $subscriptions,
            'unread' => $this->notificationRepository->countUnreadForUser($user),
            'isPro' => $this->accessService->isPro($user),
            'freeCap' => $this->accessService->getFreeCap(),
            'proSubscription' => $this->proService->getSubscription($user->getUserIdentifier()),
        ]);
    }

    #[Route('/notifications/subscriptions', name: 'notifications_subscriptions', methods: ['GET'])]
    public function subscriptions(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $subscriptions = $this->subscriptionRepository->findActiveForUser($user);

        // Resolve magazine titles for PUBLICATION subscriptions (coordinate → title map).
        $coordinateTitles = [];
        $magazineRepo = $this->em->getRepository(Magazine::class);
        foreach ($subscriptions as $sub) {
            if ($sub->getSourceType() !== NotificationSourceTypeEnum::PUBLICATION) {
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

        return $this->render('notifications/subscriptions.html.twig', [
            'subscriptions' => $subscriptions,
            'coordinateTitles' => $coordinateTitles,
            'isPro' => $this->accessService->isPro($user),
            'freeCap' => $this->accessService->getFreeCap(),
            'currentCount' => $this->subscriptionRepository->countActiveForUser($user),
            'proSubscription' => $this->proService->getSubscription($user->getUserIdentifier()),
        ]);
    }

    #[Route('/notifications/subscriptions', name: 'notifications_subscriptions_add', methods: ['POST'])]
    public function addSubscription(Request $request): Response
    {
        $redirectTo = $this->resolveRedirectPath($request) ?? $this->generateUrl('notifications_subscriptions');

        if (!$this->isCsrfTokenValid('notification-subscribe', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'notifications.errors.invalidInput');
            return $this->redirect($redirectTo);
        }

        /** @var User $user */
        $user = $this->getUser();
        $raw = trim((string) $request->request->get('identifier', ''));
        if ($raw === '') {
            $this->addFlash('error', 'notifications.errors.invalidInput');
            return $this->redirect($redirectTo);
        }

        try {
            [$type, $value, $label] = $this->parseIdentifier($raw);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', 'notifications.errors.invalidInput');
            return $this->redirect($redirectTo);
        }

        // Paywall check
        $blockReason = $this->accessService->blockReason($user, $type);
        if ($blockReason !== null) {
            $this->addFlash('error', $blockReason);
            return $this->redirectToRoute('notifications_pro_index');
        }

        if ($this->subscriptionRepository->findOneForUser($user, $type, $value) !== null) {
            $this->addFlash('error', 'notifications.errors.alreadySubscribed');
            return $this->redirect($redirectTo);
        }

        $subscription = new NotificationSubscription($user, $type, $value, $label);
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

    #[Route('/notifications/subscriptions/{id}', name: 'notifications_subscriptions_remove', methods: ['POST', 'DELETE'])]
    public function removeSubscription(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('notification-unsubscribe', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'notifications.errors.invalidInput');
            return $this->redirectToRoute('notifications_subscriptions');
        }

        /** @var User $user */
        $user = $this->getUser();
        $sub = $this->subscriptionRepository->find($id);
        if ($sub === null || $sub->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($sub);
        $this->em->flush();

        return $this->redirectToRoute('notifications_subscriptions');
    }

    // ----- JSON API for the bell/toast -----

    #[Route('/api/notifications/unread-count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse([
            'unread' => $this->notificationRepository->countUnreadForUser($user),
            'unseen' => $this->notificationRepository->countUnseenForUser($user),
        ]);
    }

    #[Route('/api/notifications/{id}/read', name: 'api_notifications_mark_read', methods: ['POST'])]
    public function markRead(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $n = $this->notificationRepository->findForUser($user, $id);
        if ($n === null) {
            return new JsonResponse(['ok' => false], 404);
        }
        $n->markRead();
        $this->em->flush();
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/notifications/mark-all-seen', name: 'api_notifications_mark_all_seen', methods: ['POST'])]
    public function markAllSeen(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $count = $this->notificationRepository->markAllSeen($user);
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
     * @return array{0: NotificationSourceTypeEnum, 1: string, 2: ?string}
     */
    private function parseIdentifier(string $raw): array
    {
        $raw = NostrKeyUtil::normalizeNostrIdentifier($raw);

        // Hex pubkey
        if (NostrKeyUtil::isHexPubkey($raw)) {
            return [NotificationSourceTypeEnum::NPUB, $raw, null];
        }

        // npub1…
        if (NostrKeyUtil::isNpub($raw)) {
            return [NotificationSourceTypeEnum::NPUB, NostrKeyUtil::npubToHex($raw), $raw];
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

    private function typeForCoordinate(int $kind): NotificationSourceTypeEnum
    {
        if ($kind === 30040) {
            return NotificationSourceTypeEnum::PUBLICATION;
        }
        // Any other supported set kind goes under NIP51_SET.
        $setKinds = [3, 10000, 10015, 30003, 30004, 30005, 30015, 39089];
        if (in_array($kind, $setKinds, true)) {
            return NotificationSourceTypeEnum::NIP51_SET;
        }
        throw new \InvalidArgumentException('Unsupported kind for notification subscription');
    }
}

