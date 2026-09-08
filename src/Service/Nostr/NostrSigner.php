<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;

/**
 * Service for signing Nostr events
 * For zap requests, we use ephemeral anonymous keys since these don't need user identity
 */
class NostrSigner
{
    public function __construct(private readonly SignatureServiceInterface $signatureService)
    {
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
        $privateKey = PrivateKey::generate();
        $keyPair = KeyPair::fromPrivateKey($privateKey, $this->signatureService);
        $event = Event::fromArray([
            'pubkey' => $keyPair->getPublicKey()->toHex(),
            'created_at' => $createdAt ?? time(),
            'kind' => $kind,
            'tags' => $tags,
            'content' => $content,
        ]);

        try {
            return $event->sign($keyPair, $this->signatureService)->toJson();
        } finally {
            $privateKey->zero();
        }

    }

    public function verify(Event $event): bool
    {
        return $event->verify($this->signatureService);
    }

    /**
     * Sign an event with a configured long-lived key.
     *
     * This is for server-owned publishing jobs; user-authenticated requests
     * must use the user's signed payload or RelayAuthSignerInterface instead.
     *
     * @param list<list<string>> $tags
     */
    public function signWithPrivateKey(
        int $kind,
        array $tags,
        string $content,
        string $privateKey,
        ?int $createdAt = null,
    ): Event {
        $key = str_starts_with(strtolower($privateKey), 'nsec')
            ? PrivateKey::fromBech32(strtolower($privateKey))
            : PrivateKey::fromHex(strtolower($privateKey));
        if ($key === null) {
            throw new \InvalidArgumentException('Invalid Nostr private key.');
        }

        $keyPair = KeyPair::fromPrivateKey($key, $this->signatureService);
        $event = Event::fromArray([
            'pubkey' => $keyPair->getPublicKey()->toHex(),
            'created_at' => $createdAt ?? time(),
            'kind' => $kind,
            'tags' => $tags,
            'content' => $content,
        ]);

        try {
            return $event->sign($keyPair, $this->signatureService);
        } finally {
            $key->zero();
        }
    }

    /**
     * Build and sign a NIP-57 zap request event (kind 9734)
     *
     * @param string $recipientPubkey Recipient's pubkey (hex)
     * @param int $amountMillisats Amount in millisatoshis
     * @param string $lnurl The LNURL or callback URL
     * @param string $comment Optional comment/note
     * @param array $relays Optional list of relays
     * @param array $zapSplits Optional zap splits configuration [['recipient' => hex, 'relay' => url, 'weight' => int]]
     * @return string JSON-encoded signed zap request
     */
    public function buildZapRequest(
        string $recipientPubkey,
        int $amountMillisats,
        string $lnurl,
        string $comment = '',
        array $relays = [],
        array $zapSplits = []
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

        // Add zap splits if provided (NIP-57)
        foreach ($zapSplits as $split) {
            $zapTag = ['zap', $split['recipient']];

            // Add relay if specified
            if (!empty($split['relay'])) {
                $zapTag[] = $split['relay'];
            } else {
                $zapTag[] = ''; // placeholder for relay position
            }

            // Add weight if specified
            if (!empty($split['weight'])) {
                $zapTag[] = (string) $split['weight'];
            }

            $tags[] = $zapTag;
        }

        return $this->signEphemeral(9734, $tags, $comment);
    }
}
