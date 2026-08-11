<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Service;

use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Contract\UserRelayProviderInterface;
use DecentNewsroom\ExpressionBundle\Service\EventResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds RuntimeContext from an authenticated user.
 * Loads contacts (kind:3), interests (kind:10015) and NIP-65 relay list
 * (kind:10002) so downstream resolvers (spells, etc.) can fan out to the
 * user's declared read relays for fresh results.
 */
final class RuntimeContextFactory
{
    public function __construct(
        private readonly EventResolver $eventResolver,
        private readonly ?UserRelayProviderInterface $userRelayProvider = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function create(string $userPubkey): RuntimeContext
    {
        $contacts = [];
        $interests = [];
        $relays = [];

        // Load kind:3 contacts
        $contactEvent = $this->eventResolver->findLatestByPubkeyAndKind($userPubkey, 3);
        if ($contactEvent) {
            foreach ($contactEvent->getTags() as $tag) {
                if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                    $contacts[] = $tag[1];
                }
            }
        }

        // Load kind:10015 interests
        $interestEvent = $this->eventResolver->findLatestByPubkeyAndKind($userPubkey, 10015);
        if ($interestEvent) {
            foreach ($interestEvent->getTags() as $tag) {
                if (($tag[0] ?? '') === 't' && isset($tag[1])) {
                    $interests[] = $tag[1];
                }
            }
        }

        // Load NIP-65 read/CONTENT relays when the host provides a relay provider.
        if ($this->userRelayProvider !== null) {
            try {
                $relays = $this->userRelayProvider->getRelaysForFetching($userPubkey);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to load user relays for RuntimeContext', [
                    'pubkey' => substr($userPubkey, 0, 12) . '…',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new RuntimeContext(
            mePubkey: $userPubkey,
            contacts: array_unique($contacts),
            interests: array_unique($interests),
            now: time(),
            relays: array_values(array_unique($relays)),
        );
    }
}
