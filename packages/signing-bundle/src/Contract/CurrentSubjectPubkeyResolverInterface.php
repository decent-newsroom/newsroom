<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Contract;

interface CurrentSubjectPubkeyResolverInterface
{
    public function resolveCurrentSubjectPubkeyHex(): ?string;
}
