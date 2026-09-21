<?php

namespace App\Twig\Components\Molecules;

use App\Service\Cache\RedisCacheService;
use App\Service\ProfileUpdateDispatcher;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class UserFromNpub
{
    public string $pubkey;
    public string $npub;
    public $user = null;

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly ProfileUpdateDispatcher $profileUpdateDispatcher,
    )
    {
    }

    /**
     * Accepts either npub or pubkey as ident. Always converts to pubkey for lookups.
     */
    public function mount(string $ident, $user = null, ?string $relayHint = null): void
    {
        $this->user = $user;
        if (PublicKey::fromHex(strtolower(trim((string) ($ident)))) !== null) {
            $this->pubkey = $ident;
            $this->npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($ident));
        } elseif (str_starts_with(strtolower(trim((string) ($ident))), 'npub1')) {
            $this->npub = $ident;
            $this->pubkey = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($ident));
        } else {
            throw new \InvalidArgumentException('UserFromNpub expects npub or hex pubkey');
        }

        if ($relayHint !== null && $this->isRelayUrl($relayHint)) {
            $this->profileUpdateDispatcher->dispatch($this->pubkey, [$relayHint]);
        }

        if ($this->user === null) {
            $userMetadata = $this->redisCacheService->getMetadata($this->pubkey);
            $this->user = $userMetadata->toStdClass(); // Convert to stdClass for template
        }
    }

    private function isRelayUrl(string $relayHint): bool
    {
        $parts = parse_url($relayHint);
        if (!is_array($parts)) {
            return false;
        }

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['ws', 'wss'], true)
            && isset($parts['host']);
    }
}
