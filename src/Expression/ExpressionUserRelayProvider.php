<?php

declare(strict_types=1);

namespace App\Expression;

use DecentNewsroom\ExpressionBundle\Contract\UserRelayProviderInterface;
use App\Service\Nostr\UserRelayListService;

final class ExpressionUserRelayProvider implements UserRelayProviderInterface
{
    public function __construct(
        private readonly UserRelayListService $relayListService,
    ) {}

    public function getRelaysForFetching(string $pubkey): array
    {
        return $this->relayListService->getRelaysForFetching($pubkey);
    }
}
