<?php

declare(strict_types=1);

namespace App\Service\Bookshelf;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;

final class BookshelfDirectoryService
{
    public const IDENTIFIER = 'my-book-collection';
    public const MAX_ITEMS = 500;

    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {
    }

    public function getLatestForUser(string $pubkey): ?Event
    {
        $events = $this->eventRepository->findAllByPubkeyAndKind(
            strtolower($pubkey),
            KindsEnum::DIRECTORY->value,
            50,
        );

        foreach ($events as $event) {
            if ($event->getDTag() === self::IDENTIFIER || $event->getSlug() === self::IDENTIFIER) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Return the editable directory tags with the stable d-tag first.
     *
     * Unknown metadata is intentionally omitted: NKBIP-04 directories contain
     * only their identifier and ordered a/e entries.
     *
     * @return array<int, array<int, string>>
     */
    public function getEditableTagsForUser(string $pubkey): array
    {
        $event = $this->getLatestForUser($pubkey);

        return $event === null
            ? [['d', self::IDENTIFIER]]
            : $this->normalizeEditableTags($event->getTags());
    }

    /**
     * @return array<int, array{
     *     type: 'a'|'e',
     *     coordinate: ?string,
     *     relay: ?string,
     *     eventId: ?string,
     *     pubkey: ?string
     * }>
     */
    public function getBookReferencesForUser(string $pubkey): array
    {
        return $this->extractBookReferences($this->getEditableTagsForUser($pubkey));
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, array{
     *     type: 'a'|'e',
     *     coordinate: ?string,
     *     relay: ?string,
     *     eventId: ?string,
     *     pubkey: ?string
     * }>
     */
    public function extractBookReferences(array $tags): array
    {
        $references = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || !is_string($tag[0] ?? null) || !is_string($tag[1] ?? null)) {
                continue;
            }

            if ($tag[0] === 'a') {
                $parts = explode(':', $tag[1], 3);
                if (
                    count($parts) !== 3
                    || (int) $parts[0] !== KindsEnum::PUBLICATION_INDEX->value
                    || preg_match('/^[a-f0-9]{64}$/i', $parts[1]) !== 1
                    || $parts[2] === ''
                ) {
                    continue;
                }

                $coordinate = sprintf(
                    '%d:%s:%s',
                    KindsEnum::PUBLICATION_INDEX->value,
                    strtolower($parts[1]),
                    $parts[2],
                );
                $key = 'a:' . $coordinate;
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $references[] = [
                    'type' => 'a',
                    'coordinate' => $coordinate,
                    'relay' => $this->normalizeRelay($tag[2] ?? null),
                    'eventId' => $this->normalizeEventId($tag[3] ?? null),
                    'pubkey' => strtolower($parts[1]),
                ];
                continue;
            }

            if ($tag[0] !== 'e') {
                continue;
            }

            $eventId = $this->normalizeEventId($tag[1]);
            if ($eventId === null || isset($seen['e:' . $eventId])) {
                continue;
            }

            $seen['e:' . $eventId] = true;
            $tagPubkey = is_string($tag[3] ?? null)
                && preg_match('/^[a-f0-9]{64}$/i', $tag[3]) === 1
                    ? strtolower($tag[3])
                    : null;
            $references[] = [
                'type' => 'e',
                'coordinate' => null,
                'relay' => $this->normalizeRelay($tag[2] ?? null),
                'eventId' => $eventId,
                'pubkey' => $tagPubkey,
            ];
        }

        return $references;
    }

    /**
     * @param array<int, mixed> $tags
     */
    public function assertValidDirectory(array $tags, string $content): void
    {
        if ($content !== '') {
            throw new \InvalidArgumentException('Directory content must be empty.');
        }

        if (!array_is_list($tags) || count($tags) > self::MAX_ITEMS + 1) {
            throw new \InvalidArgumentException('Directory tags are invalid or exceed the item limit.');
        }

        $dTagCount = 0;
        $itemCount = 0;

        foreach ($tags as $tag) {
            if (!is_array($tag) || !array_is_list($tag) || !is_string($tag[0] ?? null)) {
                throw new \InvalidArgumentException('Directory tags must be arrays.');
            }

            if ($tag[0] === 'd') {
                if (($tag[1] ?? null) !== self::IDENTIFIER) {
                    throw new \InvalidArgumentException('Directory identifier is invalid.');
                }
                $dTagCount++;
                continue;
            }

            if ($tag[0] === 'a') {
                $this->assertValidATag($tag);
                $itemCount++;
                continue;
            }

            if ($tag[0] === 'e') {
                $this->assertValidETag($tag);
                $itemCount++;
                continue;
            }

            throw new \InvalidArgumentException('Directory events may contain only d, a, and e tags.');
        }

        if ($dTagCount !== 1 || $itemCount > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('Directory must contain one identifier and no more than 500 items.');
        }
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, array<int, string>>
     */
    private function normalizeEditableTags(array $tags): array
    {
        $normalized = [['d', self::IDENTIFIER]];

        foreach ($tags as $tag) {
            if (!is_array($tag) || !in_array($tag[0] ?? null, ['a', 'e'], true)) {
                continue;
            }

            try {
                if ($tag[0] === 'a') {
                    $this->assertValidATag($tag);
                } else {
                    $this->assertValidETag($tag);
                }
            } catch (\InvalidArgumentException) {
                continue;
            }

            $normalized[] = array_values(array_map(
                static fn (mixed $value): string => (string) $value,
                $tag,
            ));

            if (count($normalized) > self::MAX_ITEMS) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $tag
     */
    private function assertValidATag(array $tag): void
    {
        if (!is_string($tag[1] ?? null)) {
            throw new \InvalidArgumentException('Directory a tags require a coordinate.');
        }

        $parts = explode(':', $tag[1], 3);
        if (
            count($parts) !== 3
            || !ctype_digit($parts[0])
            || (int) $parts[0] < 1
            || preg_match('/^[a-f0-9]{64}$/i', $parts[1]) !== 1
            || $parts[2] === ''
        ) {
            throw new \InvalidArgumentException('Directory a tag coordinate is invalid.');
        }

        $this->assertRelay($tag[2] ?? null);
        if (isset($tag[3]) && $tag[3] !== '' && $this->normalizeEventId($tag[3]) === null) {
            throw new \InvalidArgumentException('Directory a tag event id is invalid.');
        }
    }

    /**
     * @param array<int, mixed> $tag
     */
    private function assertValidETag(array $tag): void
    {
        if ($this->normalizeEventId($tag[1] ?? null) === null) {
            throw new \InvalidArgumentException('Directory e tag event id is invalid.');
        }

        $this->assertRelay($tag[2] ?? null);
        if (
            isset($tag[3])
            && $tag[3] !== ''
            && (!is_string($tag[3]) || preg_match('/^[a-f0-9]{64}$/i', $tag[3]) !== 1)
        ) {
            throw new \InvalidArgumentException('Directory e tag pubkey is invalid.');
        }
    }

    private function assertRelay(mixed $relay): void
    {
        if ($relay !== null && $relay !== '' && $this->normalizeRelay($relay) === null) {
            throw new \InvalidArgumentException('Directory relay hint is invalid.');
        }
    }

    private function normalizeRelay(mixed $relay): ?string
    {
        if (!is_string($relay) || $relay === '' || filter_var($relay, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($relay, PHP_URL_SCHEME));

        return in_array($scheme, ['ws', 'wss'], true) ? $relay : null;
    }

    private function normalizeEventId(mixed $eventId): ?string
    {
        return is_string($eventId) && preg_match('/^[a-f0-9]{64}$/i', $eventId) === 1
            ? strtolower($eventId)
            : null;
    }
}
