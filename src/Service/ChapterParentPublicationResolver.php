<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ChapterParentPublicationResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(param: 'bookshelf.books_api_base_url')]
        private readonly string $booksApiBaseUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{title: string, eventId: ?string}|null
     */
    public function resolve(string $chapterCoordinate): ?array
    {
        try {
            $localParents = $this->eventRepository->findReferencingEvents(
                'a',
                $chapterCoordinate,
                [KindsEnum::PUBLICATION_INDEX->value],
                1,
            );

            $localParent = $localParents[0] ?? null;
            if ($localParent instanceof Event) {
                return $this->fromEvent($localParent);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to resolve a chapter parent publication locally.', [
                'coordinate' => $chapterCoordinate,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->resolveFromBooksApi($chapterCoordinate);
    }

    /**
     * @return array{title: string, eventId: ?string}|null
     */
    private function resolveFromBooksApi(string $chapterCoordinate): ?array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->booksApiBaseUrl, '/') . '/books/api/events/filter',
                [
                    'headers' => ['Accept' => 'application/json'],
                    'json' => [
                        'kinds' => [KindsEnum::PUBLICATION_INDEX->value],
                        '#a' => [$chapterCoordinate],
                        'limit' => 10,
                    ],
                ],
            );

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $events = $response->toArray(false);
            foreach ($events as $event) {
                if (!is_array($event) || !$this->referencesChapter($event, $chapterCoordinate)) {
                    continue;
                }

                return $this->fromApiEvent($event);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to resolve a chapter parent publication from the Books API.', [
                'coordinate' => $chapterCoordinate,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function referencesChapter(array $event, string $chapterCoordinate): bool
    {
        if ((int) ($event['kind'] ?? 0) !== KindsEnum::PUBLICATION_INDEX->value || !is_array($event['tags'] ?? null)) {
            return false;
        }

        foreach ($event['tags'] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'a' && ($tag[1] ?? null) === $chapterCoordinate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{title: string, eventId: ?string}
     */
    private function fromApiEvent(array $event): array
    {
        $title = null;
        foreach ($event['tags'] as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1]) || !is_string($tag[1])) {
                continue;
            }

            if ($tag[0] === 'title') {
                $title = trim($tag[1]);
            }
        }

        if ($title === null || $title === '') {
            foreach ($event['tags'] as $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 'd' && is_string($tag[1] ?? null)) {
                    $title = trim($tag[1]);
                    break;
                }
            }
        }
        if ($title === null && isset($event['id']) && is_string($event['id'])) {
            $title = substr($event['id'], 0, 12);
        }

        return [
            'title' => (string) $title,
            'eventId' => $this->eventId($event['id'] ?? null),
        ];
    }

    /**
     * @return array{title: string, eventId: ?string}
     */
    private function fromEvent(Event $event): array
    {
        $slug = $event->getDTag() ?: $event->getSlug();
        $title = trim((string) ($event->getTitle() ?? ''));
        if ($title === '') {
            $title = $slug ?: substr($event->getId(), 0, 12);
        }

        return [
            'title' => $title,
            'eventId' => $this->eventId($event->getId()),
        ];
    }

    private function eventId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }
}
