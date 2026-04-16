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

        $this->logger->debug('Resolving nested expression by address', [
            'address' => $address,
            'depth' => count($ctx->visitedExpressions),
        ]);

        $event = $this->findExpression($address);

        return $this->executeExpression($event, $address, $ctx);
    }

    /**
     * Execute an expression from an already-resolved Event (skips DB lookup).
     *
     * @return NormalizedItem[]
     */
    public function executeEvent(\App\Entity\Event $event, RuntimeContext $ctx): array
    {
        $dTag = null;
        foreach ($event->getTags() as $tag) {
            if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                $dTag = $tag[1];
                break;
            }
        }
        $address = "{$event->getKind()}:{$event->getPubkey()}:{$dTag}";

        if (in_array($address, $ctx->visitedExpressions, true)) {
            $this->logger->warning('Circular expression reference detected', [
                'address' => $address,
                'visited' => $ctx->visitedExpressions,
            ]);
            throw new CycleException("Circular reference: {$address}");
        }

        $ctx->visitedExpressions[] = $address;

        $this->logger->debug('Executing nested expression from pre-resolved event', [
            'eventId' => $event->getId(),
            'address' => $address,
            'depth' => count($ctx->visitedExpressions),
        ]);

        return $this->executeExpression($event, $address, $ctx);
    }

    /** @return NormalizedItem[] */
    private function executeExpression(\App\Entity\Event $event, string $label, RuntimeContext $ctx): array
    {
        $start = microtime(true);
        $pipeline = $this->parser->parse($event);
        $result = $this->runner->run($pipeline, $ctx, $this->sourceResolver);

        $this->logger->debug('Nested expression resolved', [
            'label' => $label,
            'depth' => count($ctx->visitedExpressions),
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
