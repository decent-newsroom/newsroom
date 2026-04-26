<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use App\Entity\User;
use App\Util\NostrKeyUtil;

/**
 * Mints short-lived HS256 JWTs for Mercure *subscribers*, scoped to each user's
 * private topics (updates stream + relay-auth challenges).
 *
 * The token is delivered to the browser via an HttpOnly cookie that the Mercure
 * hub honors automatically on SSE requests to /.well-known/mercure — no JS
 * Authorization header is required or desired (EventSource does not support them).
 */
class MercureSubscriberTokenService
{
    /** Token lifetime in seconds (8 hours). Cookie refreshed when remaining TTL < 1h. */
    public const TTL_SECONDS = 28800;
    public const COOKIE_NAME = 'mercureAuthorization';

    public function __construct(
        private readonly string $mercureJwtSecret,
    ) {
    }

    /**
     * Return the Mercure topic URL for a given user's private updates stream.
     * The numeric DB id (not the pubkey) is used so the topic is not trivially
     * guessable by knowing someone's public identity.
     */
    public static function topicForUser(User $user): string
    {
        return sprintf('/users/%d/updates', (int) $user->getId());
    }

    /**
     * Return the Mercure topic URL for a given user's NIP-42 relay AUTH challenges.
     * Keyed by hex pubkey so the gateway can publish without knowing the DB id.
     */
    public static function relayAuthTopicForUser(User $user): ?string
    {
        $npub = $user->getNpub();
        if ($npub === null) {
            return null;
        }
        try {
            $hex = NostrKeyUtil::npubToHex($npub);
        } catch (\Throwable) {
            return null;
        }
        return '/relay-auth/' . $hex;
    }

    /**
     * Mint a subscriber JWT scoped to this user's private Mercure topics:
     *   - /users/{id}/updates  — live update notifications
     *   - /relay-auth/{hex}    — NIP-42 AUTH challenge delivery (relay gateway)
     */
    public function mintForUser(User $user, int $ttlSeconds = self::TTL_SECONDS): string
    {
        $topics = [self::topicForUser($user)];
        $relayAuthTopic = self::relayAuthTopicForUser($user);
        if ($relayAuthTopic !== null) {
            $topics[] = $relayAuthTopic;
        }

        $now = time();
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'mercure' => [
                'subscribe' => $topics,
            ],
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];

        $h = $this->base64UrlEncode((string) json_encode($header));
        $p = $this->base64UrlEncode((string) json_encode($payload));
        $sig = hash_hmac('sha256', $h . '.' . $p, $this->mercureJwtSecret, true);
        $s = $this->base64UrlEncode($sig);

        return $h . '.' . $p . '.' . $s;
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

