<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatCommunity;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the current request to a ChatCommunity (if any).
 */
class ChatCommunityResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function resolve(): ?ChatCommunity
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        return $request->attributes->get('_chat_community');
    }
}

