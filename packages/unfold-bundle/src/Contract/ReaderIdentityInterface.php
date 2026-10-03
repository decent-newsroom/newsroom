<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

interface ReaderIdentityInterface
{
    public function pubkey(): ?string;

    public function loginUrl(Request $request, string $contentPath): string;
}
