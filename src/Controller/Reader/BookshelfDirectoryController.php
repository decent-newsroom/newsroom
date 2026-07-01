<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Enum\KindsEnum;
use App\Helper\NavigationBuilderTrait;
use App\Service\Bookshelf\BookshelfDirectoryService;
use App\Service\GenericEventProjector;
use App\Service\Mercury\MercuryApiException;
use App\Service\Mercury\MercuryBookService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use swentel\nostr\Event\Event as NostrEvent;

final class BookshelfDirectoryController extends AbstractController
{
    use NavigationBuilderTrait;

    #[Route('/bookshelf/my-books', name: 'bookshelf_my_books', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        BookshelfDirectoryService $directoryService,
        MercuryBookService $bookService,
    ): Response {
        $user = $this->getUser();
        \assert($user !== null);

        $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        $directoryTags = $directoryService->getEditableTagsForUser($pubkey);
        $references = $directoryService->extractBookReferences($directoryTags);
        $available = true;

        try {
            $books = $bookService->getBooksForReferences($references);
        } catch (MercuryApiException) {
            $books = [];
            $available = false;
        }

        return $this->render('bookshelf/my_books.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav(true),
            'books' => $books,
            'available' => $available,
            'referenceCount' => count($references),
            'missingBookCount' => max(0, count($references) - count($books)),
            'directoryTags' => $directoryTags,
            'directoryIdentifier' => BookshelfDirectoryService::IDENTIFIER,
        ]);
    }

    #[Route('/api/bookshelf/directory', name: 'bookshelf_directory_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publish(
        Request $request,
        BookshelfDirectoryService $directoryService,
        GenericEventProjector $eventProjector,
        UserRelayListService $userRelayListService,
        NostrClient $nostrClient,
        LoggerInterface $logger,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('bookshelf_directory', $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON request.'], Response::HTTP_BAD_REQUEST);
        }

        $signedEvent = $data['event'] ?? null;
        if (!is_array($signedEvent)) {
            return $this->json(['error' => 'Missing signed event.'], Response::HTTP_BAD_REQUEST);
        }

        foreach (['id', 'pubkey', 'created_at', 'kind', 'tags', 'sig'] as $field) {
            if (!array_key_exists($field, $signedEvent)) {
                return $this->json(
                    ['error' => sprintf('Missing required event field: %s.', $field)],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        if ((int) $signedEvent['kind'] !== KindsEnum::DIRECTORY->value) {
            return $this->json(['error' => 'Invalid directory event kind.'], Response::HTTP_BAD_REQUEST);
        }

        $content = is_string($signedEvent['content'] ?? null) ? $signedEvent['content'] : '';
        if (!is_array($signedEvent['tags'])) {
            return $this->json(['error' => 'Invalid directory tags.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $directoryService->assertValidDirectory($signedEvent['tags'], $content);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        \assert($user !== null);
        $authenticatedPubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        if (!is_string($signedEvent['pubkey']) || !hash_equals($authenticatedPubkey, strtolower($signedEvent['pubkey']))) {
            return $this->json(
                ['error' => 'Signed event does not belong to the authenticated user.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $event = new NostrEvent();
        $event->setId((string) $signedEvent['id']);
        $event->setPublicKey((string) $signedEvent['pubkey']);
        $event->setCreatedAt((int) $signedEvent['created_at']);
        $event->setKind((int) $signedEvent['kind']);
        $event->setTags($signedEvent['tags']);
        $event->setContent($content);
        $event->setSignature((string) $signedEvent['sig']);

        if (!$event->verify()) {
            return $this->json(['error' => 'Event signature verification failed.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $rawEvent = (object) [
                'id' => $event->getId(),
                'pubkey' => $event->getPublicKey(),
                'created_at' => $event->getCreatedAt(),
                'kind' => $event->getKind(),
                'tags' => $event->getTags(),
                'content' => $event->getContent(),
                'sig' => $event->getSignature(),
            ];
            $eventProjector->projectEventFromNostrEvent($rawEvent, 'local');

            $relays = $userRelayListService->getRelaysForPublishing($authenticatedPubkey);
            $relayResults = $nostrClient->publishEvent($event, $relays, 10);
        } catch (\Throwable $exception) {
            $logger->error('Failed to publish bookshelf directory event.', [
                'event_id' => $event->getId(),
                'pubkey' => substr($authenticatedPubkey, 0, 16) . '...',
                'error' => $exception->getMessage(),
            ]);

            return $this->json(
                ['error' => 'The directory was saved locally but relay publishing failed.'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        $successCount = 0;
        foreach ($relayResults as $result) {
            if ($result === true || (is_object($result) && ($result->type ?? null) === 'OK')) {
                $successCount++;
            }
        }

        return $this->json([
            'success' => true,
            'event_id' => $event->getId(),
            'relays_success' => $successCount,
        ]);
    }
}
