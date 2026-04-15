<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Normalizer;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Term;
use App\Repository\EventRepository;

/**
 * Count referencing events by kind (engagement signals).
 */
final class CountNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {}

    public function getName(): string { return 'count'; }

    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        if (empty($term->extraValues)) {
            return 0.0;
        }

        $kinds = array_map('intval', $term->extraValues);

        $eventId = $item->getId();
        $coordinate = null;
        $kind = $item->getKind();
        if ($kind >= 30000 && $kind < 40000) {
            $dValues = $item->getTagValues('d');
            $d = $dValues[0] ?? '';
            $coordinate = "{$kind}:{$item->getPubkey()}:{$d}";
        }

        return (float) $this->eventRepository->countReferencingEvents(
            eventId: $eventId,
            coordinate: $coordinate,
            kinds: $kinds,
        );
    }
}

