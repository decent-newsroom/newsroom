<?php

declare(strict_types=1);

namespace App\ChatBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Matches requests to chat community subdomains.
 * Used by security.yaml to activate the chat firewall.
 */
class ChatRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return $request->attributes->has('_chat_community');
    }
}

