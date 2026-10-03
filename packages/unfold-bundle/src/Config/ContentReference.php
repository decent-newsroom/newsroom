<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Content\ContentKindPolicy;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;

final readonly class ContentReference
{
    /** @param list<string> $relayHints */
    private function __construct(
        public int $kind,
        public string $pubkey,
        public string $identifier,
        public string $coordinate,
        public array $relayHints,
    ) {}

    public static function fromInput(string $input): self
    {
        if (strlen($input) > 32768) {
            throw new \InvalidArgumentException('unfold_content.invalid_reference');
        }
        $input = ltrim($input);
        if (str_starts_with(strtolower($input), 'nostr:')) {
            $input = substr($input, 6);
        }
        if (preg_match('/^([0-9]+):([a-fA-F0-9]{64}):(.+)$/Ds', $input, $matches) === 1) {
            return self::create((int) $matches[1], $matches[2], $matches[3], []);
        }
        $input = trim($input);
        if (!str_starts_with(strtolower($input), 'naddr1')) {
            throw new \InvalidArgumentException('unfold_content.invalid_reference');
        }
        try {
            $data = (new Bech32($input))->data;
        } catch (\Exception | \TypeError $e) {
            // The NIP-19 decoder uses Exception for checksum errors and typed
            // properties for required TLV fields; both denote invalid input here.
            throw new \InvalidArgumentException('unfold_content.invalid_reference', 0, $e);
        }
        if (!$data instanceof NAddr) {
            throw new \InvalidArgumentException('unfold_content.invalid_reference');
        }
        $hints = [];
        foreach ($data->relays as $relay) {
            if (!self::validRelay($relay)) {
                throw new \InvalidArgumentException('unfold_content.invalid_reference');
            }
            if (!in_array($relay, $hints, true)) {
                $hints[] = $relay;
            }
            if (count($hints) > 5) {
                throw new \InvalidArgumentException('unfold_content.invalid_reference');
            }
        }

        return self::create($data->kind, $data->pubkey, $data->identifier, $hints);
    }

    public static function fromEvent(NostrEvent $event): self
    {
        $identifier = null;
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) !== 'd') {
                continue;
            }
            if ($identifier !== null || !is_string($tag[1] ?? null)) {
                throw new \InvalidArgumentException('unfold_content.invalid_reference');
            }
            $identifier = $tag[1];
        }
        if ($identifier === null) {
            throw new \InvalidArgumentException('unfold_content.invalid_reference');
        }

        return self::create($event->kind, $event->pubkey, $identifier, []);
    }

    public function matches(NostrEvent $event): bool
    {
        try {
            return self::fromEvent($event)->coordinate === $this->coordinate;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function isScoped(NostrEvent $event): bool
    {
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 's') {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $relayHints */
    private static function create(int $kind, string $pubkey, string $identifier, array $relayHints): self
    {
        $pubkey = strtolower($pubkey);
        $coordinate = $kind . ':' . $pubkey . ':' . $identifier;
        if (!ContentKindPolicy::supports($kind) || preg_match('/^[a-f0-9]{64}$/D', $pubkey) !== 1
            || trim($identifier) === '' || strlen($coordinate) > 500
            || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1
            || preg_match('//u', $identifier) !== 1) {
            throw new \InvalidArgumentException('unfold_content.invalid_reference');
        }

        return new self($kind, $pubkey, $identifier, $coordinate, $relayHints);
    }

    private static function validRelay(string $relay): bool
    {
        $parts = parse_url($relay);

        return strlen($relay) <= 2048 && preg_match('/[\x00-\x1F\x7F]/', $relay) !== 1
            && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['ws', 'wss'], true)
            && isset($parts['host']) && $parts['host'] !== ''
            && !isset($parts['user']) && !isset($parts['pass']);
    }
}
