<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Runner\Normalizer;

use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Model\Term;
use DecentNewsroom\ExpressionBundle\Service\EventResolver;

/**
 * Count referencing events by kind (engagement signals).
 */
final class CountNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly EventResolver $eventResolver,
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

        return (float) $this->eventResolver->countReferencingEvents(
            eventId: $eventId,
            coordinate: $coordinate,
            kinds: $kinds,
        );
    }
}
