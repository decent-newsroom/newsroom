<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Contract\ReaderIdentityInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ReaderIdentityAdapter implements ReaderIdentityInterface
{
    public function __construct(private PublicationAdminIdentityAdapter $identity, private ReaderLogin $login) {}

    public function pubkey(): ?string
    {
        return $this->identity->pubkey();
    }

    public function loginUrl(Request $request, string $contentPath): string
    {
        return $this->login->loginUrl($request, $contentPath);
    }
}
