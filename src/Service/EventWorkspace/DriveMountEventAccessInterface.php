<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReference;
use App\Entity\Event;

interface DriveMountEventAccessInterface
{
    /**
     * @param list<EventReference> $references
     * @return array<int, Event> source position => authorized metadata-only event
     */
    public function readableArticleTargets(array $references): array;

    public function isReadableTarget(Event $event): bool;
}
