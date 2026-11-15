<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Article;
use App\Entity\Event as AppEvent;
use BitWasp\Bech32\Exception\Bech32Exception;
use Exception;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class Filters extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('shortenNpub', [$this, 'shortenNpub']),
            new TwigFilter('linkify', [$this, 'linkify'], ['is_safe' => ['html']]),
            new TwigFilter('mentionify', [$this, 'mentionify'], ['is_safe' => ['html']]),
            new TwigFilter('nEncode', [$this, 'nEncode']),
            new TwigFilter('naddrEncode', [$this, 'naddrEncode']),
            new TwigFilter('naddrEncodeCoordinate', [$this, 'naddrEncodeCoordinate']),
            new TwigFilter('naddrEncodeCoordinateOrNull', [$this, 'naddrEncodeCoordinateOrNull']),
            new TwigFilter('isValidNostrReferenceValue', [$this, 'isValidNostrReferenceValue']),
            new TwigFilter('isSafeExternalNostrReference', [$this, 'isSafeExternalNostrReference']),
            new TwigFilter('toNpub', [$this, 'toNpub']),
            new TwigFilter('toHex', [$this, 'toHex']),
            new TwigFilter('pastelHue', [$this, 'pastelHue']),
            new TwigFilter('json_decode', [$this, 'jsonDecode']),
        ];
    }

    public function shortenNpub(string $npub): string
    {
        return substr($npub, 0, 8) . '…' . substr($npub, -4);
    }

    public function linkify(string $text): string
    {
        return preg_replace_callback(
            '#\b((https?://|www\.)[^\s<]+)#i',
            function ($matches) {
                $url = $matches[0];
                $href = str_starts_with($url, 'http') ? $url : 'https://' . $url;

                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                );
            },
            $text
        );
    }

    public function mentionify(string $text): string
    {
        return preg_replace_callback(
            '/(?<![a-z0-9_\/])(?<prefix>nostr:|@)?(?<npub>npub1[0-9a-z]{10,})(?![0-9a-z])/i',
            function ($matches) {
                $npub = $matches['npub'];
                $short = substr($npub, 0, 8) . '…' . substr($npub, -4);
                $prefix = strtolower($matches['prefix'] ?? '');

                $label = match ($prefix) {
                    '@' => '@' . $short,
                    'nostr:' => 'nostr:' . $short,
                    default => $short,
                };

                return sprintf(
                    '<a href="/p/%s" class="mention-link">%s</a>',
                    htmlspecialchars($npub, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                );
            },
            $text
        );
    }

    public function nEncode(string $eventId): string
    {
        $nip19 = new NostrNip19Service();
        try {
            return $nip19->encodeNote($eventId);
        } catch (Bech32Exception) {
            return $eventId; // Return original if encoding fails
        }
    }

    /**
     * @throws Exception
     */
    public function naddrEncode(Article|AppEvent $entity): string
    {
        $nip19 = new NostrNip19Service();

        // Handle App\Entity\Event (e.g., magazines, lists)
        if ($entity instanceof AppEvent) {
            $slug = $entity->getSlug();
            if ($slug === null) {
                return $nip19->encodeNote($entity->getEventId() ?? $entity->getId());
            }

            $pubkey = $entity->getPubkey();
            $kind = $entity->getKind();

            return $nip19->encodeAddr($pubkey, $slug, $kind);
        }

        // Handle Article entity
        if ($entity->getRaw() === null || $entity->getPubkey() === null || $entity->getKind() === null || $entity->getSlug() === null) {
            return $nip19->encodeNote($entity->getEventId());
        }

        return $nip19->encodeAddr($entity->getPubkey(), $entity->getSlug(), $entity->getKind()->value);
    }

    public function naddrEncodeCoordinate(string $coordinate, ?string $relayHint = null): string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !preg_match('/^[0-9a-f]{64}$/i', $parts[1])) {
            return $coordinate;
        }

        return (new NostrNip19Service())->encodeAddr(
            $parts[1],
            $parts[2],
            (int) $parts[0],
            $relayHint !== null && $relayHint !== '' ? [$relayHint] : [],
        );
    }

    /**
     * Encode only when the NIP-19 result round-trips the requested identity.
     * Unlike the legacy filter, invalid input never returns the raw coordinate.
     */
    public function naddrEncodeCoordinateOrNull(mixed $coordinate, mixed $relayHint = null): ?string
    {
        if (!is_string($coordinate)) {
            return null;
        }
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0])
            || !preg_match('/^[0-9a-f]{64}$/i', $parts[1])
            || strlen($parts[2]) > 255
            // The installed encoder handles each UTF-8 character as one byte.
            || preg_match('/[^\x00-\x7f]/', $parts[2])) {
            return null;
        }
        $kind = ltrim($parts[0], '0');
        $kind = $kind === '' ? '0' : $kind;
        if (strlen($kind) > 10 || (strlen($kind) === 10 && strcmp($kind, '4294967295') > 0)) {
            return null;
        }

        try {
            $encoded = $this->naddrEncodeCoordinate($coordinate, is_string($relayHint) ? $relayHint : null);
            $decoded = (new NostrNip19Service())->decode($encoded);
            if (($decoded['kind'] ?? null) !== (int) $kind
                || ($decoded['pubkey'] ?? null) !== strtolower($parts[1])
                || ($decoded['identifier'] ?? null) !== $parts[2]) {
                return null;
            }

            return $encoded;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isValidNostrReferenceValue(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    public function isSafeExternalNostrReference(mixed $value): bool
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    public function toNpub(string $hexPubKey): string
    {
        try {
            // Normalize hex to lowercase
            $hexPubKey = strtolower(trim($hexPubKey));
            $key = new NostrKeyService();
            return $key->convertPublicKeyToBech32($hexPubKey);
        } catch (\Throwable) {
            return $hexPubKey; // Return original hex if conversion fails
        }
    }

    public function toHex(string $npub): string
    {
        try {
            // Normalize bech32 to lowercase (mixed case causes checksum errors)
            $npub = strtolower(trim($npub));
            $key = new NostrKeyService();
            return $key->convertToHex($npub);
        } catch (\Throwable) {
            return $npub; // Return original string if conversion fails
        }
    }

    /**
     * Returns a deterministic hue (0–359) from a string, for use in pastel backgrounds.
     */
    public function pastelHue(string $input): int
    {
        return abs(crc32($input)) % 360;
    }

    public function jsonDecode(string $json): mixed
    {
        return json_decode($json, true);
    }
}
