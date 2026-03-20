<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatUser;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;

/**
 * Signs Nostr events on behalf of a chat user using their custodial key.
 * The private key is decrypted transiently and never stored beyond the sign call.
 */
class ChatEventSigner
{
    public function __construct(
        private readonly ChatKeyManager $keyManager,
    ) {}

    /**
     * Sign a Nostr event for the given user.
     *
     * @return string JSON-encoded signed event
     */
    public function signForUser(ChatUser $user, int $kind, array $tags, string $content = ''): string
    {
        if (!$user->isCustodial()) {
            throw new \RuntimeException('Cannot server-sign for a self-sovereign user. Use client-side signing.');
        }

        $privateKey = $this->keyManager->decryptPrivateKey($user);

        try {
            $event = new Event();
            $event->setKind($kind);
            $event->setTags($tags);
            $event->setContent($content);
            $event->setCreatedAt(time());

            $signer = new Sign();
            $signer->signEvent($event, $privateKey);

            return $event->toJson();
        } finally {
            // Overwrite the key in memory (best-effort — PHP strings are immutable)
            unset($privateKey);
        }
    }
}

