<?php

namespace App\ChatBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * ChatBundle — Private Community Chat Module
 *
 * Self-contained bundle for invite-only, community-scoped chat
 * backed by custodial Nostr identities and a private relay (NIP-28).
 */
class ChatBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/ChatBundle';
    }
}

