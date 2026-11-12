<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReferenceRole;
use App\Entity\Event;

final readonly class BookshelfReferencePage
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private EventWorkspaceSourceAdapter $sources,
        private EventPresentationFactory $presentations,
    ) {}

    /** @return array<string, mixed> */
    public function fromAuthorizedEvent(Event $event, int $page = 1): array
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Directory page numbers must be positive.');
        }

        $source = $this->sources->fromBookshelfDirectory($event);
        $result = [
            'eventId' => $source->eventId,
            'page' => $page,
            'start' => 1,
            'entries' => [],
            'hasPrevious' => $page > 1,
            'hasNext' => false,
        ];
        if ($page - 1 > intdiv(PHP_INT_MAX - 1, self::PAGE_SIZE)) {
            return $result;
        }

        $offset = ($page - 1) * self::PAGE_SIZE;
        $result['start'] = $offset + 1;
        $tags = array_values($source->getRawTags() ?? []);
        $index = 0;
        foreach ($this->presentations->fromAuthorizedEvent($event)->references as $reference) {
            if ($reference->role !== EventReferenceRole::Content) {
                continue;
            }
            if ($index++ < $offset) {
                continue;
            }
            if (count($result['entries']) === self::PAGE_SIZE) {
                $result['hasNext'] = true;
                break;
            }

            $tag = $tags[$reference->position];
            $hasPin = $reference->tagName === 'a'
                && array_key_exists(3, $tag) && $tag[3] !== '';
            $result['entries'][] = [
                'reference' => $reference,
                'rawVersionPin' => $hasPin && is_string($tag[3]) ? $tag[3] : null,
                'invalidVersionPin' => $hasPin && $reference->versionEventId === null,
            ];
        }

        return $result;
    }
}
