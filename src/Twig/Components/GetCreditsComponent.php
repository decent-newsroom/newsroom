<?php

namespace App\Twig\Components;

use App\Credits\Service\CreditsManager;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * @deprecated Since v0.0.21 — Search credits system has been deprecated. This component is no longer used.
 */
#[AsLiveComponent]
final class GetCreditsComponent
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    public function __construct(
        private readonly CreditsManager $creditsManager,
        private readonly TokenStorageInterface $tokenStorage)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[LiveAction]
    public function grantVoucher(): void
    {
        $npub = $this->tokenStorage->getToken()?->getUserIdentifier();
        if ($npub) {
            $this->creditsManager->addCredits($npub, 5, 'voucher');
        }

        // Dispatch event to notify parent
        $this->emit('creditsAdded');
    }
}

