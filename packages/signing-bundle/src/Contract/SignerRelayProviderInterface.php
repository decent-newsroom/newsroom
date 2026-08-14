<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Contract;

interface SignerRelayProviderInterface
{
    /** @return list<string> Relay URLs that remote signers should use for NIP-46 RPC. */
    public function getSignerRelays(): array;
}
