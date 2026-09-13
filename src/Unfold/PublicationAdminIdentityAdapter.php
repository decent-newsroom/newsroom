<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Contract\PublicationAdminIdentityInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class PublicationAdminIdentityAdapter implements PublicationAdminIdentityInterface
{
    public function __construct(private Security $security, private PublicationAdminLogin $login) {}

    public function pubkey(): ?string
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return null;
        }
        try {
            $pubkey = PublicKey::fromBech32(strtolower($user->getUserIdentifier()))?->toHex();
            if ($pubkey === null || preg_match('/^[a-f0-9]{64}$/D', $pubkey) !== 1) {
                throw new \InvalidArgumentException();
            }
            return $pubkey;
        } catch (\Throwable $e) {
            throw new AccessDeniedHttpException(previous: $e);
        }
    }

    public function loginUrl(Request $request): string
    {
        return $this->login->loginUrl($request);
    }
}
