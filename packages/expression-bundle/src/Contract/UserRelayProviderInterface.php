<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Contract;

interface UserRelayProviderInterface
{
    /** @return string[] */
    public function getRelaysForFetching(string $pubkey): array;
}
