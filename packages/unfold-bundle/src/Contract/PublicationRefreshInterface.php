<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface PublicationRefreshInterface
{
    public function refresh(string $coordinate): void;
}
