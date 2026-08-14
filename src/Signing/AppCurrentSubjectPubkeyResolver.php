<?php

declare(strict_types=1);

namespace App\Signing;

use App\Entity\User;
use DecentNewsroom\SigningBundle\Contract\CurrentSubjectPubkeyResolverInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class AppCurrentSubjectPubkeyResolver implements CurrentSubjectPubkeyResolverInterface
{
    public function __construct(private Security $security)
    {
    }

    public function resolveCurrentSubjectPubkeyHex(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $npub = $user->getNpub() ?? $user->getUserIdentifier();
        $npub = strtolower(trim($npub));
        if (str_starts_with($npub, 'nostr:')) {
            $npub = substr($npub, 6);
        }

        if (!str_starts_with($npub, 'npub1')) {
            return null;
        }

        try {
            return PublicKey::fromBech32($npub)?->toHex();
        } catch (\Throwable) {
            return null;
        }
    }
}
