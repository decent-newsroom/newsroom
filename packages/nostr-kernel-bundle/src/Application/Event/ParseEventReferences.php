<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Application\Event;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventReferenceParserInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventReference;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventReferenceType;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

final readonly class ParseEventReferences implements EventReferenceParserInterface
{
    public function parse(NostrEvent $event): array
    {
        $references = [];

        foreach ($event->tags()->raw() as $tag) {
            $name = $tag[0] ?? null;
            $value = $tag[1] ?? null;

            if (!\is_string($name) || !\is_scalar($value)) {
                continue;
            }

            $type = $this->resolveType($name);
            if ($type === null) {
                continue;
            }

            $stringValue = \trim((string) $value);
            if ($stringValue === '') {
                continue;
            }

            $references[] = new EventReference($type, $stringValue, $tag);
        }

        return $references;
    }

    private function resolveType(string $tagName): ?EventReferenceType
    {
        return match (\strtolower($tagName)) {
            'e' => EventReferenceType::EVENT,
            'p' => EventReferenceType::PUBKEY,
            'a' => EventReferenceType::ADDRESSABLE_COORDINATE,
            't' => EventReferenceType::TOPIC,
            'r' => EventReferenceType::EXTERNAL_URL,
            default => null,
        };
    }
}

