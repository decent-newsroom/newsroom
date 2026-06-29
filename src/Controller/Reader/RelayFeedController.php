<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Enum\RelayPurpose;
use App\Message\StartRelayFeedMessage;
use App\Service\Nostr\RelayFeedBufferService;
use App\Service\Nostr\RelayRegistry;
use App\Service\UserMuteListService;
use App\Util\NostrKeyUtil;
use App\Util\RelayUrlNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Relay Feed — browse the live article stream from any Nostr relay.
 *
 * Flow:
 *   1. User visits /relay-feed and enters a relay URL (wss://...).
 *   2. POST /relay-feed triggers a StartRelayFeedMessage dispatched to the
 *      async_low_priority worker queue.
 *   3. The handler opens a WebSocket, subscribes to kind:30023 events, and
 *      - publishes each raw card to the Mercure topic /relay-feed/{key}
 *      - buffers up to 100 cards in Redis so late-joining viewers get history
 *   4. GET /relay-feed/{key} renders the buffered cards and opens an EventSource
 *      for live updates via the Stimulus relay-feed controller.
 *   5. GET /relay-feed/{key}/keepalive refreshes the Redis active flag so the
 *      worker keeps re-dispatching while viewers are present.
 */
class RelayFeedController extends AbstractController
{
    #[Route('/relay-feed', name: 'relay_feed_index', methods: ['GET'])]
    public function index(RelayRegistry $relays, NostrKeyUtil $keyUtil): Response
    {
        return $this->render('relay_feed/index.html.twig', [
            'allowed_relays' => $this->allowedRelays($relays),
            'recipients'     => $this->recipients($keyUtil),
        ]);
    }


    /**
     * Start a relay feed subscription.
     * Validates the URL against the configured allowlist, makes a stable key,
     * dispatches the async message, and redirects.
     */
    #[Route('/relay-feed', name: 'relay_feed_start', methods: ['POST'])]
    public function start(
        Request $request,
        RelayFeedBufferService $buffer,
        MessageBusInterface $bus,
        RelayRegistry $relays,
        NostrKeyUtil $keyUtil,
    ): Response {
        $relayUrl    = trim((string) $request->get('relay_url', ''));
        $allowed     = $this->allowedRelays($relays);
        $normalized  = RelayUrlNormalizer::normalize($relayUrl);

        $isAllowed = false;
        foreach ($allowed as $allowedUrl) {
            if (RelayUrlNormalizer::normalize($allowedUrl) === $normalized) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return $this->render('relay_feed/index.html.twig', [
                'error'          => 'relay_feed.error_not_allowed',
                'relay_url'      => $relayUrl,
                'allowed_relays' => $allowed,
                'recipients'     => $this->recipients($keyUtil),
            ]);
        }

        $key = $buffer->makeKey($normalized);

        // Persist the URL and mark as active before dispatching, so the handler
        // immediately finds both when it starts.
        $buffer->storeRelayUrl($key, $normalized);
        $buffer->markActive($key);

        // Only dispatch a new subscription if one is not already running.
        // isActive() was just set, so we check the buffer instead: if it already
        // has events the worker is (or was) running; dispatching again is harmless
        // because the handler checks isActive() before connecting.
        $bus->dispatch(new StartRelayFeedMessage($normalized, $key));

        return $this->redirectToRoute('relay_feed_show', ['key' => $key]);
    }

    /**
     * Show the live relay feed page — renders buffered cards and subscribes to Mercure.
     */
    #[Route('/relay-feed/{key}', name: 'relay_feed_show', requirements: ['key' => '[a-f0-9]{16}'], methods: ['GET'])]
    public function show(
        string $key,
        RelayFeedBufferService $buffer,
        // UserMuteListService $muteListService,
    ): Response {
        $relayUrl = $buffer->getRelayUrl($key);

        if ($relayUrl === null) {
            throw new NotFoundHttpException('Feed not found or expired. Please start a new feed.');
        }

        // Renew the active flag so the handler keeps re-dispatching.
        $buffer->markActive($key);

        // ── Resolve user-level mute list (kind 10000, NIP-51) ──
//        $mutedPubkeys = [];
//        $user = $this->getUser();
//        if ($user !== null) {
//            try {
//                $pubkeyHex    = NostrKeyUtil::npubToHex($user->getUserIdentifier());
//                $mutedPubkeys = $muteListService->getMutedPubkeys($pubkeyHex);
//            } catch (\Throwable) {
//                // Non-critical — proceed without user mutes
//            }
//        }

        // Filter buffered articles server-side so muted authors are hidden on page load.
        $articles = array_values(array_filter(
            $buffer->getBuffer($key),
            static fn (array $article): bool => !empty($article['d_tag']),
        ));
//        if ($mutedPubkeys !== []) {
//            $mutedSet = array_flip($mutedPubkeys);
//            $articles = array_values(
//                array_filter($articles, fn(array $a) => !isset($mutedSet[$a['pubkey'] ?? '']))
//            );
//        }

        return $this->render('relay_feed/feed.html.twig', [
            'relay_url'      => $relayUrl,
            'relay_key'      => $key,
            'articles'       => $articles,
            'mercure_topic'  => '/relay-feed/' . $key,
            'muted_pubkeys'  => [] // $mutedPubkeys,
        ]);
    }

    /**
     * Keepalive endpoint — refresh the active flag so the worker keeps re-dispatching.
     * Called by the Stimulus controller every ~5 minutes.
     */
    #[Route('/relay-feed/{key}/keepalive', name: 'relay_feed_keepalive', requirements: ['key' => '[a-f0-9]{16}'], methods: ['POST'])]
    public function keepalive(
        string $key,
        RelayFeedBufferService $buffer,
    ): JsonResponse {
        $relayUrl = $buffer->getRelayUrl($key);

        if ($relayUrl === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Feed expired'], 404);
        }

        $buffer->markActive($key);

        return new JsonResponse(['ok' => true]);
    }

    /** @return string[] */
    private function recipients(NostrKeyUtil $keyUtil): array
    {
        return [
            $keyUtil->npubToHex('npub1ez09adke4vy8udk3y2skwst8q5chjgqzym9lpq4u58zf96zcl7kqyry2lz'),
            $keyUtil->npubToHex('npub1636uujeewag8zv8593lcvdrwlymgqre6uax4anuq3y5qehqey05sl8qpl4'),
        ];
    }

    /**
     * Relays available for feed subscriptions: project relay (public URL) + content relays.
     * LOCAL is intentionally excluded — it is the internal Docker hostname, not user-facing.
     *
     * @return string[]
     */
    private function allowedRelays(RelayRegistry $relays): array
    {
        return array_unique(array_merge(
            $relays->getForPurpose(RelayPurpose::PROJECT),
            $relays->getForPurpose(RelayPurpose::CONTENT),
        ));
    }
}






