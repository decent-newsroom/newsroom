<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Contract;

interface SignerRelayProviderInterface
{
    /**
     * @return string[] Relay URLs that remote signers should use for NIP-46 RPC.
     */
    public function getSignerRelays(): array;
}