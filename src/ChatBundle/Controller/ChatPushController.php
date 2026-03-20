<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Entity\ChatPushSubscription;
use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Repository\ChatPushSubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChatPushController extends AbstractController
{
    public function __construct(
        private readonly ChatPushSubscriptionRepository $subscriptionRepo,
        private readonly string $vapidPublicKey,
    ) {}

    /**
     * Returns the VAPID public key so the browser can subscribe.
     */
    public function vapidKey(): JsonResponse
    {
        return new JsonResponse(['publicKey' => $this->vapidPublicKey]);
    }

    /**
     * Store a push subscription for the current chat user.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $user = $this->getChatUser();

        $data = json_decode($request->getContent(), true);
        if (!isset($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'])) {
            return new JsonResponse(['error' => 'Invalid subscription data'], Response::HTTP_BAD_REQUEST);
        }

        // Upsert: if endpoint already exists, update keys
        $existing = $this->subscriptionRepo->findByEndpoint($data['endpoint']);
        if ($existing !== null) {
            $existing->setPublicKey($data['keys']['p256dh']);
            $existing->setAuthToken($data['keys']['auth']);
            if (isset($data['expirationTime']) && $data['expirationTime'] !== null) {
                $existing->setExpiresAt(new \DateTimeImmutable('@' . (int)($data['expirationTime'] / 1000)));
            }
            $this->subscriptionRepo->getEntityManager()->flush();
            return new JsonResponse(['status' => 'updated']);
        }

        $sub = new ChatPushSubscription();
        $sub->setChatUser($user);
        $sub->setEndpoint($data['endpoint']);
        $sub->setPublicKey($data['keys']['p256dh']);
        $sub->setAuthToken($data['keys']['auth']);
        if (isset($data['expirationTime']) && $data['expirationTime'] !== null) {
            $sub->setExpiresAt(new \DateTimeImmutable('@' . (int)($data['expirationTime'] / 1000)));
        }

        $em = $this->subscriptionRepo->getEntityManager();
        $em->persist($sub);
        $em->flush();

        return new JsonResponse(['status' => 'subscribed'], Response::HTTP_CREATED);
    }

    /**
     * Remove a push subscription by endpoint.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $this->getChatUser(); // ensure authenticated

        $data = json_decode($request->getContent(), true);
        if (!isset($data['endpoint'])) {
            return new JsonResponse(['error' => 'Missing endpoint'], Response::HTTP_BAD_REQUEST);
        }

        $this->subscriptionRepo->deleteByEndpoint($data['endpoint']);

        return new JsonResponse(['status' => 'unsubscribed']);
    }

    private function getChatUser(): ChatUser
    {
        $user = $this->getUser();
        if (!$user instanceof ChatUser) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }
}
