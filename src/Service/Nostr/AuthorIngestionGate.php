<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Exception\BannedAuthorEvent;
use App\Repository\BannedPubkeyRepository;
use Psr\Log\LoggerInterface;

class AuthorIngestionGate
{
    public function __construct(
        private readonly BannedPubkeyRepository $bans,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function assertAllowed(string $pubkey): void
    {
        if ($this->bans->isBanned($pubkey)) {
            $this->logger->debug('Blocked ingestion from permanently banned author', ['pubkey' => $pubkey]);
            throw new BannedAuthorEvent('Event author is permanently banned by this instance.');
        }
    }
}
