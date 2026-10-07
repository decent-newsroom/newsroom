<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Repository\BannedPubkeyRepository;
use App\Repository\UserEntityRepository;
use App\Service\Nostr\NostrKeyService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ContentAuthorAccessPolicy
{
    public function __construct(
        private readonly UserEntityRepository $users,
        private readonly BannedPubkeyRepository $bannedPubkeys,
        private readonly NostrKeyService $keys,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isSuppressed(string $identifier): bool
    {
        $pubkey = $this->keys->convertToHex($identifier);
        $suppressed = $this->bannedPubkeys->isBanned($pubkey)
            || $this->users->isAdminMuted($this->keys->convertPublicKeyToBech32($pubkey));

        if ($suppressed) {
            $this->logger->debug('Suppressed author content request', ['pubkey' => $pubkey]);
        }

        return $suppressed;
    }

    public function assertReadable(string $identifier): void
    {
        if ($this->isSuppressed($identifier)) {
            throw new NotFoundHttpException('Content not found.');
        }
    }
}
