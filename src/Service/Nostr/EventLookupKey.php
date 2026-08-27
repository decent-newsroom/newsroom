<?php

declare(strict_types=1);

namespace App\Service\Nostr;

final class EventLookupKey
{
    public static function forNevent(string $eventId): string
    {
        return 'nevent:' . $eventId;
    }

    public static function forNaddr(int $kind, string $pubkey, string $identifier): string
    {
        return sprintf('naddr:%d:%s:%s', $kind, $pubkey, $identifier);
    }

    public static function topic(string $lookupKey): string
    {
        return sprintf('/event-fetch/%s', $lookupKey);
    }
}
