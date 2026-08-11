<?php

declare(strict_types=1);

namespace App\Expression;

use DecentNewsroom\ExpressionBundle\Contract\RelaySelectorInterface;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\UserRelayListService;
use App\Util\RelayUrlNormalizer;

final class ExpressionRelaySelector implements RelaySelectorInterface
{
    public function __construct(
        private readonly RelayRegistry $relayRegistry,
        private readonly UserRelayListService $userRelayListService,
    ) {}

    public function getDefaultRelays(): array
    {
        return $this->relayRegistry->getDefaultRelays();
    }

    public function getContentRelays(): array
    {
        return $this->relayRegistry->getContentRelays();
    }

    public function getAuthorRelays(string $pubkey): array
    {
        return $this->userRelayListService->getAuthorRelays($pubkey);
    }

    public function ensureLocalRelay(array $relayUrls): array
    {
        return $this->relayRegistry->ensureLocalRelayInList($relayUrls);
    }

    public function canonicalize(string $relayUrl): string
    {
        return RelayUrlNormalizer::normalize($relayUrl);
    }
}
