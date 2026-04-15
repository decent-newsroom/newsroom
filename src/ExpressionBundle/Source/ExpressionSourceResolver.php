<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Exception\CycleException;
use App\ExpressionBundle\Exception\UnresolvedRefException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Parser\ExpressionParser;
use App\ExpressionBundle\Runner\ExpressionRunner;
use App\Repository\EventRepository;

/**
 * Recursively evaluates nested kind:30880 expressions with cycle detection.
 */
final class ExpressionSourceResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ExpressionParser $parser,
        private readonly ExpressionRunner $runner,
        private readonly SourceResolverInterface $sourceResolver,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        if (in_array($address, $ctx->visitedExpressions, true)) {
            throw new CycleException("Circular reference: {$address}");
        }

        $ctx->visitedExpressions[] = $address;

        $event = $this->findExpression($address);
        $pipeline = $this->parser->parse($event);
        return $this->runner->run($pipeline, $ctx, $this->sourceResolver);
    }

    private function findExpression(string $address): \App\Entity\Event
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);

        $event = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $d);
        if ($event === null) {
            throw new UnresolvedRefException("Expression not found: {$address}");
        }

        return $event;
    }
}


