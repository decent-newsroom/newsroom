<?php

namespace App\Twig\Components\Molecules;

use App\Service\Cache\RedisCacheService;
use App\Util\NostrKeyUtil;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class UserFromNpub
{
    public string $pubkey;
    public string $npub;
    public $user = null;

    public function __construct(private readonly RedisCacheService $redisCacheService)
    {
    }

    /**
     * Accepts either npub or pubkey as ident. Always converts to pubkey for lookups.
     */
    public function mount(string $ident, $user = null): void
    {
        $this->user = $user;
        if (NostrKeyUtil::isHexPubkey($ident)) {
            $this->pubkey = $ident;
            $this->npub = NostrKeyUtil::hexToNpub($ident);
        } elseif (NostrKeyUtil::isNpub($ident)) {
            $this->npub = $ident;
            $this->pubkey = NostrKeyUtil::npubToHex($ident);
        } else {
            throw new \InvalidArgumentException('UserFromNpub expects npub or hex pubkey');
        }
        if ($this->user === null) {
            $userMetadata = $this->redisCacheService->getMetadata($this->pubkey);
            $this->user = $userMetadata->toStdClass(); // Convert to stdClass for template
        }
    }
}
