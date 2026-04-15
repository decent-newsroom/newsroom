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
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        if (in_array($address, $ctx->visitedExpressions, true)) {
            $this->logger->warning('Circular expression reference detected', [
                'address' => $address,
                'visited' => $ctx->visitedExpressions,
            ]);
            throw new CycleException("Circular reference: {$address}");
        }

        $ctx->visitedExpressions[] = $address;
        $depth = count($ctx->visitedExpressions);

        $this->logger->debug('Resolving nested expression', [
            'address' => $address,
            'depth' => $depth,
        ]);

        $start = microtime(true);
        $event = $this->findExpression($address);
        $pipeline = $this->parser->parse($event);
        $result = $this->runner->run($pipeline, $ctx, $this->sourceResolver);

        $this->logger->debug('Nested expression resolved', [
            'address' => $address,
            'depth' => $depth,
            'resultItems' => count($result),
            'ms' => round((microtime(true) - $start) * 1000),
        ]);

        return $result;
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
