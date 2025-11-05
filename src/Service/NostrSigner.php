<?php

declare(strict_types=1);

namespace App\Service;

use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

/**
 * Service for signing Nostr events
 * For zap requests, we use ephemeral anonymous keys since these don't need user identity
 */
class NostrSigner
{
    private Key $key;

    public function __construct()
    {
        $this->key = new Key();
    }

    /**
     * Sign a Nostr event with an ephemeral key
     * Returns the signed event as JSON string
     *
     * @param int $kind Event kind
     * @param array $tags Event tags
     * @param string $content Event content
     * @param int|null $createdAt Optional timestamp (defaults to now)
     * @return string JSON-encoded signed event
     */
    public function signEphemeral(int $kind, array $tags, string $content = '', ?int $createdAt = null): string
    {
        // Generate ephemeral key pair for anonymous zaps
        $privateKey = bin2hex(random_bytes(32));
        $publicKey = $this->key->getPublicKey($privateKey);

        $event = new Event();
        $event->setKind($kind);
        $event->setTags($tags);
        $event->setContent($content);
        $event->setCreatedAt($createdAt ?? time());

        // Sign the event using the Sign class
        $signer = new Sign();
        $signer->signEvent($event, $privateKey);

        // Return as JSON using Event's built-in method
        return $event->toJson();
    }

    /**
     * Build and sign a NIP-57 zap request event (kind 9734)
     *
     * @param string $recipientPubkey Recipient's pubkey (hex)
     * @param int $amountMillisats Amount in millisatoshis
     * @param string $lnurl The LNURL or callback URL
     * @param string $comment Optional comment/note
     * @param array $relays Optional list of relays
     * @return string JSON-encoded signed zap request
     */
    public function buildZapRequest(
        string $recipientPubkey,
        int $amountMillisats,
        string $lnurl,
        string $comment = '',
        array $relays = []
    ): string {
        $tags = [
            ['p', $recipientPubkey],
            ['amount', (string) $amountMillisats],
            ['lnurl', $lnurl],
        ];

        // Add relays if provided
        foreach ($relays as $relay) {
            $tags[] = ['relays', $relay];
        }

        return $this->signEphemeral(9734, $tags, $comment);
    }
}

