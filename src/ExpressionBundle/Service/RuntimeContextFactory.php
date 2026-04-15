<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Service;

use App\ExpressionBundle\Model\RuntimeContext;
use App\Repository\EventRepository;

/**
 * Builds RuntimeContext from an authenticated user.
 * Loads contacts (kind:3) and interests (kind:10015) from local DB.
 */
final class RuntimeContextFactory
{
    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {}

    public function create(string $userPubkey): RuntimeContext
    {
        $contacts = [];
        $interests = [];

        // Load kind:3 contacts
        $contactEvent = $this->eventRepository->findLatestByPubkeyAndKind($userPubkey, 3);
        if ($contactEvent) {
            foreach ($contactEvent->getTags() as $tag) {
                if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                    $contacts[] = $tag[1];
                }
            }
        }

        // Load kind:10015 interests
        $interestEvent = $this->eventRepository->findLatestByPubkeyAndKind($userPubkey, 10015);
        if ($interestEvent) {
            foreach ($interestEvent->getTags() as $tag) {
                if (($tag[0] ?? '') === 't' && isset($tag[1])) {
                    $interests[] = $tag[1];
                }
            }
        }

        return new RuntimeContext(
            mePubkey: $userPubkey,
            contacts: array_unique($contacts),
            interests: array_unique($interests),
            now: time(),
        );
    }
}

