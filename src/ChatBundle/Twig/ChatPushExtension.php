<?php

declare(strict_types=1);

namespace App\ChatBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the VAPID public key as a Twig global so the push prompt can use it.
 */
class ChatPushExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly string $vapidPublicKey,
    ) {}

    public function getGlobals(): array
    {
        return [
            'vapidPublicKey' => $this->vapidPublicKey,
        ];
    }
}

