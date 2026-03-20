<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatUser;

class ChatProfileService
{
    public function __construct(
        private readonly ChatEventSigner $signer,
        private readonly ChatRelayClient $relayClient,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {}

    public function updateProfile(ChatUser $user, string $displayName, ?string $about = null): void
    {
        $user->setDisplayName($displayName);
        $user->setAbout($about);
        $this->em->flush();

        // Publish kind 0 metadata to chat relay
        $metadata = json_encode(array_filter([
            'name' => $displayName,
            'about' => $about,
        ]));

        $signedJson = $this->signer->signForUser($user, 0, [], $metadata);
        $this->relayClient->publish($signedJson, $user->getCommunity());
    }
}

