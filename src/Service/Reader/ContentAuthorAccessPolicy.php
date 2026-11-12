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

    /**
     * Resolve author suppression for a bounded set without one policy query per
     * event. Event fields must not be returned until this policy has run.
     *
     * @param list<string> $pubkeys Lowercase or mixed-case hexadecimal keys
     * @return list<string> Suppressed lowercase hexadecimal keys
     */
    public function suppressedPubkeys(array $pubkeys): array
    {
        $pubkeys = array_values(array_unique(array_filter(
            array_map('strtolower', $pubkeys),
            static fn (string $pubkey): bool => preg_match('/^[0-9a-f]{64}$/', $pubkey) === 1,
        )));
        if ($pubkeys === []) {
            return [];
        }

        $suppressed = array_fill_keys($this->bannedPubkeys->findBannedPubkeys($pubkeys), true);
        $npubToHex = [];
        foreach ($pubkeys as $pubkey) {
            $npubToHex[$this->keys->convertPublicKeyToBech32($pubkey)] = $pubkey;
        }
        foreach ($this->users->findAdminMutedNpubs(array_keys($npubToHex)) as $npub) {
            if (isset($npubToHex[$npub])) {
                $suppressed[$npubToHex[$npub]] = true;
            }
        }

        foreach (array_keys($suppressed) as $pubkey) {
            $this->logger->debug('Suppressed author content request', ['pubkey' => $pubkey]);
        }

        return array_keys($suppressed);
    }
}
