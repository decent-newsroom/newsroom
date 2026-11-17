<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\UpdateSourceTypeEnum;
use App\Helper\NavigationBuilderTrait;
use App\Repository\UpdateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\Update\SubscriptionSource;
use App\Service\Update\SubscriptionFeed;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Local subscription feed and source management.
 */
#[IsGranted('ROLE_USER')]
class UpdatesController extends AbstractController
{
    use NavigationBuilderTrait;

    public function __construct(
        private readonly UpdateRepository $updateRepository,
        private readonly UpdateSubscriptionRepository $subscriptionRepository,
        private readonly SubscriptionSource $sources,
        private readonly SubscriptionFeed $feed,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/updates', name: 'updates_legacy', methods: ['GET'])]
    #[Route('/subscriptions', name: 'updates_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $before = $request->query->get('before');
        $before = is_string($before) && ctype_digit($before) ? $before : null;
        $page = $this->feed->page($user, $before);
        return $this->render('updates/index.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            'updates' => $page['items'],
            'nextCursor' => $page['nextCursor'],
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/updates/subscriptions', name: 'updates_subscriptions_legacy', methods: ['GET'])]
    #[Route('/subscriptions/sources', name: 'updates_subscriptions', methods: ['GET'])]
    public function subscriptions(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $subscriptions = $this->subscriptionRepository->findActiveForUser($user);

        $sourceTitles = [];
        foreach ($subscriptions as $sub) {
            $type = $sub->getSourceType();
            if ($type === UpdateSourceTypeEnum::NPUB) {
                continue;
            }
            $event = $this->sources->currentEvent($type, $sub->getSourceValue());
            if ($event === null || !$this->feed->isEventReadable($event)) {
                continue;
            }
            /** @var array<array-key, mixed> $tags */
            $tags = $event->getTags();
            foreach ($tags as $tag) {
                if (is_array($tag) && in_array($tag[0] ?? null, ['title', 'name'], true)
                    && is_string($tag[1] ?? null) && trim($tag[1]) !== '') {
                    $sourceTitles[$sub->getSourceValue()] = $tag[1];
                    break;
                }
            }
        }

        return $this->render('updates/subscriptions.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            'subscriptions' => $subscriptions,
            'sourceTitles' => $sourceTitles,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
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
            [$type, $value, $label] = $this->sources->parse($raw);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', 'updates.errors.invalidInput');
            return $this->redirect($redirectTo);
        }

        $subscription = $this->subscriptionRepository->findOneForUser($user, $type, $value)
            ?? new UpdateSubscription($user, $type, $value, $label);
        $subscription->reactivate();
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

        if (!str_starts_with($redirectTo, '/') || str_starts_with($redirectTo, '//')
            || str_contains($redirectTo, '\\') || preg_match('/[\x00-\x20]/', $redirectTo)) {
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

        $sub->setActive(false);
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
            ...$this->feed->unreadCounts($user),
        ]);
    }

    #[Route('/subscriptions/items', name: 'subscription_feed_items', methods: ['GET'])]
    public function items(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $page = $this->feed->page($user);
        return $this->render('updates/_feed.html.twig', [
            'updates' => $page['items'],
            'nextCursor' => $page['nextCursor'],
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/api/updates/{id}/read', name: 'api_updates_mark_read', methods: ['POST'])]
    public function markRead(Request $request, string $id): JsonResponse
    {
        if (!$this->isCsrfTokenValid('subscription-read', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], 403);
        }
        /** @var User $user */
        $user = $this->getUser();
        $n = $this->updateRepository->findForUser($user, $id);
        if ($n === null || !$this->feed->isReadable($n)) {
            return new JsonResponse(['ok' => false], 404);
        }
        $n->markRead();
        $this->em->flush();
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/updates/mark-all-seen', name: 'api_updates_mark_all_seen', methods: ['POST'])]
    public function markAllSeen(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('subscription-read', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], 403);
        }
        /** @var User $user */
        $user = $this->getUser();
        $count = 0;
        foreach ($this->updateRepository->unreadForUser($user) as $update) {
            if ($update->getSeenAt() === null && $this->feed->isReadable($update)) {
                $update->markSeen();
                ++$count;
            }
        }
        $this->em->flush();
        return new JsonResponse(['ok' => true, 'updated' => $count]);
    }

    #[Route('/subscriptions/read/{id}', name: 'subscription_read', methods: ['POST'])]
    public function read(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('subscription-read', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $user */
        $user = $this->getUser();
        $update = $this->updateRepository->findForUser($user, $id);
        if ($update === null || !$this->feed->isReadable($update)) {
            throw $this->createNotFoundException();
        }
        $update->markRead();
        $this->em->flush();
        return $this->redirectToRoute('updates_index');
    }
}
