<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;

/** An existing kind-30040 reading-list reference used as a publication category. */
final readonly class CategoryReference
{
    /** @param list<string> $relayHints */
    private function __construct(public string $coordinate, public array $relayHints) {}

    public static function fromInput(string $input): self
    {
        $input = trim($input);
        if (str_starts_with(strtolower($input), 'nostr:')) {
            $input = substr($input, 6);
        }

        if (preg_match('/^30040:([a-fA-F0-9]{64}):(.+)$/Ds', $input, $matches) === 1) {
            return new self(self::coordinate($matches[1], $matches[2]), []);
        }

        try {
            $decoded = new Bech32(strtolower($input));
            if (!$decoded->data instanceof NAddr || $decoded->data->kind !== 30040) {
                throw new \InvalidArgumentException();
            }
            $hints = [];
            foreach ($decoded->data->relays ?? [] as $relay) {
                if (self::validRelay($relay) && !in_array($relay, $hints, true)) {
                    $hints[] = $relay;
                }
                if (count($hints) === 5) {
                    break;
                }
            }

            return new self(self::coordinate($decoded->data->pubkey, $decoded->data->identifier), $hints);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('unfold_admin.invalid_category', 0, $e);
        }
    }

    private static function coordinate(string $pubkey, string $identifier): string
    {
        $coordinate = '30040:' . strtolower($pubkey) . ':' . $identifier;
        if (strlen($coordinate) > 500 || preg_match('/^[a-fA-F0-9]{64}$/D', $pubkey) !== 1
            || trim($identifier) === '' || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new \InvalidArgumentException('unfold_admin.invalid_category');
        }

        return $coordinate;
    }

    private static function validRelay(string $relay): bool
    {
        $parts = parse_url($relay);
        return strlen($relay) <= 2048 && preg_match('/[\x00-\x1F\x7F]/', $relay) !== 1
            && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['ws', 'wss'], true)
            && isset($parts['host']) && $parts['host'] !== '' && !isset($parts['user'], $parts['pass']);
    }
}
