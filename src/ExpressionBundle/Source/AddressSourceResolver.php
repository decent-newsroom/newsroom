<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Dispatches address references (a-tag) by kind to specialized resolvers.
 */
final class AddressSourceResolver
{
    public function __construct(
        private readonly ExpressionSourceResolver $expressionResolver,
        private readonly SpellSourceResolver $spellResolver,
        private readonly ListSourceResolver $listResolver,
        private readonly GenericEventResolver $genericEventResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        $parts = explode(':', $address, 3);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException("Invalid address format: {$address}");
        }

        $kind = (int) $parts[0];
        $resolverType = match (true) {
            $kind === 30880 => 'expression',
            $kind === 777   => 'spell',
            in_array($kind, [30003, 30004, 30005, 30006, 10003], true) => 'list',
            default         => 'generic',
        };

        $this->logger->debug('Dispatching address reference', [
            'address' => $address,
            'kind' => $kind,
            'resolver' => $resolverType,
        ]);

        return match ($resolverType) {
            'expression' => $this->expressionResolver->resolve($address, $ctx),
            'spell'      => $this->spellResolver->resolve($address, $ctx),
            'list'       => $this->listResolver->resolve($address, $ctx),
            'generic'    => $this->genericEventResolver->resolve($address, $ctx),
        };
    }
}
