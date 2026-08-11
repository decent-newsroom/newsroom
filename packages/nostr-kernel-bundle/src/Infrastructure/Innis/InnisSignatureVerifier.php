<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventSignatureVerifierInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidNostrEvent;
use Innis\Nostr\Core\Domain\Entity\Event as InnisEvent;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;

final readonly class InnisSignatureVerifier implements EventSignatureVerifierInterface
{
    public function __construct(private SignatureServiceInterface $signatureService)
    {
    }

    /**
     * @param NostrEvent $event
     * @return bool
     * @throws InvalidNostrEvent
     */
    public function verify(NostrEvent $event): bool
    {
        try {
            $innisEvent = InnisEvent::fromArray($event->toArray());

            return $innisEvent->verify($this->signatureService);
        } catch (\Throwable $e) {
            throw new InvalidNostrEvent(
                message: 'Failed to verify Nostr event signature through innis/nostr-core.',
                previous: $e,
            );
        }
   }
}
