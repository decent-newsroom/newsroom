<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;

/** A published article reference selected by the publication owner. */
final readonly class AboutArticleReference
{
    /** @param list<string> $relayHints */
    private function __construct(public string $coordinate, public array $relayHints) {}

    public static function fromInput(string $input): self
    {
        $input = trim($input);
        if (str_starts_with(strtolower($input), 'nostr:')) {
            $input = substr($input, 6);
        }

        if (preg_match('/^30023:([a-fA-F0-9]{64}):(.+)$/Ds', $input, $matches) === 1) {
            return new self(self::coordinate($matches[1], $matches[2]), []);
        }

        try {
            $decoded = new Bech32(strtolower($input));
            $data = $decoded->data;
            if (!$data instanceof NAddr) {
                throw new \InvalidArgumentException();
            }
            if ($data->kind !== 30023) {
                throw new \InvalidArgumentException();
            }
            $coordinate = self::coordinate($data->pubkey, $data->identifier);
            $relayHints = [];
            foreach ($data->relays as $relay) {
                if (self::validRelay($relay) && !in_array($relay, $relayHints, true)) {
                    $relayHints[] = $relay;
                }
                if (count($relayHints) === 5) {
                    break;
                }
            }

            return new self($coordinate, $relayHints);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('unfold_setup.invalid_about_article', 0, $e);
        }
    }

    private static function coordinate(string $pubkey, string $identifier): string
    {
        $coordinate = '30023:' . strtolower($pubkey) . ':' . $identifier;
        if (strlen($coordinate) > 500 || preg_match('/^[a-f0-9]{64}$/D', $pubkey) !== 1 && preg_match('/^[a-fA-F0-9]{64}$/D', $pubkey) !== 1
            || trim($identifier) === '' || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
        }

        return $coordinate;
    }

    private static function validRelay(string $relay): bool
    {
        if (strlen($relay) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $relay) === 1) {
            return false;
        }
        $parts = parse_url($relay);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['ws', 'wss'], true)
            && isset($parts['host']) && $parts['host'] !== ''
            && !isset($parts['user']) && !isset($parts['pass']);
    }
}
