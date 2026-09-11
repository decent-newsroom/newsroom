<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use App\UnfoldBundle\Contract\EventReadGatewayInterface;
use App\UnfoldBundle\Contract\NostrEvent;

final readonly class EventReadGatewayAdapter implements EventReadGatewayInterface
{
    public function __construct(
        private EventRepository $eventRepository,
        private NostrClient $nostrClient,
    ) {
    }

    public function findByCoordinate(string $coordinate, array $relayHints = []): ?NostrEvent
    {
        $normalized = $this->normalizeCoordinate($coordinate);
        [$kind, $pubkey, $identifier] = $this->parseCoordinate($normalized);

        $event = $this->eventRepository->findByNaddr($kind, $pubkey, $identifier);
        if ($event !== null) {
            return $this->fromEntity($event);
        }

        $event = $this->nostrClient->getEventByNaddr([
            'kind' => $kind,
            'pubkey' => $pubkey,
            'identifier' => $identifier,
            'relays' => $relayHints,
        ]);

        return $event === null ? null : $this->fromObject($event);
    }

    /**
     * @param list<string> $coordinates
     * @return array<string, NostrEvent>
     */
    public function findByCoordinates(array $coordinates): array
    {
        $normalizedCoordinates = [];
        foreach ($coordinates as $coordinate) {
            try {
                $normalized = $this->normalizeCoordinate($coordinate);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $normalizedCoordinates[$normalized] = true;
        }

        if ($normalizedCoordinates === []) {
            return [];
        }

        $result = [];
        foreach ($this->eventRepository->findByCoordinates(array_keys($normalizedCoordinates)) as $coordinate => $event) {
            $normalized = $this->coordinateFromEntity($event);
            if (isset($normalizedCoordinates[$normalized])) {
                $result[$normalized] = $this->fromEntity($event);
            }
        }

        $missing = array_values(array_diff(array_keys($normalizedCoordinates), array_keys($result)));
        if ($missing === []) {
            return $result;
        }

        foreach ($this->nostrClient->getEventsByCoordinates($missing) as $event) {
            $dto = $this->fromObject($event);
            $coordinate = $this->coordinateFromDto($dto);
            if (isset($normalizedCoordinates[$coordinate])) {
                $result[$coordinate] = $dto;
            }
        }

        return $result;
    }

    private function fromEntity(object $event): NostrEvent
    {
        return new NostrEvent(
            id: $event->getId(),
            pubkey: strtolower($event->getPubkey()),
            kind: $event->getKind(),
            content: $event->getContent(),
            tags: $this->normalizeTags($event->getTags()),
            createdAt: $event->getCreatedAt(),
            sig: $event->getSig(),
        );
    }

    private function fromObject(object $event): NostrEvent
    {
        return new NostrEvent(
            id: $this->readString($event, 'id'),
            pubkey: strtolower($this->readString($event, 'pubkey')),
            kind: $this->readInt($event, 'kind'),
            content: $this->readString($event, 'content'),
            tags: $this->normalizeTags($this->readValue($event, 'tags')),
            createdAt: $this->readInt($event, 'created_at'),
            sig: $this->readString($event, 'sig'),
        );
    }

    private function coordinateFromEntity(object $event): string
    {
        $identifier = '';
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd' && is_string($tag[1] ?? null)) {
                $identifier = $tag[1];
                break;
            }
        }

        return $this->normalizeCoordinate(sprintf('%d:%s:%s', $event->getKind(), $event->getPubkey(), $identifier));
    }

    private function coordinateFromDto(NostrEvent $event): string
    {
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 'd' && isset($tag[1])) {
                return $this->normalizeCoordinate(sprintf('%d:%s:%s', $event->kind, $event->pubkey, $tag[1]));
            }
        }

        return '';
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function parseCoordinate(string $coordinate): array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || $parts[1] === '' || $parts[2] === '') {
            throw new \InvalidArgumentException(sprintf('Invalid Nostr coordinate: %s', $coordinate));
        }

        return [(int) $parts[0], $parts[1], $parts[2]];
    }

    private function normalizeCoordinate(string $coordinate): string
    {
        [$kind, $pubkey, $identifier] = $this->parseCoordinate($coordinate);

        return sprintf('%d:%s:%s', $kind, strtolower($pubkey), $identifier);
    }

    private function readValue(object $event, string $property): mixed
    {
        if (property_exists($event, $property)) {
            return $event->{$property};
        }

        $getterNames = [
            'created_at' => 'getCreatedAt',
            'pubkey' => 'getPubkey',
            'content' => 'getContent',
            'tags' => 'getTags',
            'sig' => 'getSig',
            'id' => 'getId',
            'kind' => 'getKind',
        ];
        if (isset($getterNames[$property]) && method_exists($event, $getterNames[$property])) {
            return $event->{$getterNames[$property]}();
        }

        $getter = 'get' . str_replace('_', '', ucwords($property, '_'));

        return method_exists($event, $getter) ? $event->{$getter}() : null;
    }

    private function readString(object $event, string $property): string
    {
        $value = $this->readValue($event, $property);

        return is_scalar($value) ? (string) $value : '';
    }

    private function readInt(object $event, string $property): int
    {
        $value = $this->readValue($event, $property);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param mixed $tags
     * @return list<list<string>>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $values = [];
            foreach ($tag as $value) {
                if (!is_scalar($value)) {
                    continue 2;
                }
                $values[] = (string) $value;
            }
            $normalized[] = array_values($values);
        }

        return $normalized;
    }
}
