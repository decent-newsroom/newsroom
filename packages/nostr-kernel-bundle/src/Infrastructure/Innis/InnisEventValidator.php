<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventValidationResult;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use Innis\Nostr\Core\Domain\Entity\Event as InnisEvent;
use Innis\Nostr\Core\Domain\Service\EventValidationServiceInterface;

final readonly class InnisEventValidator implements EventValidatorInterface
{
    public function __construct(private EventValidationServiceInterface $eventValidationService)
    {
    }

    public function validate(NostrEvent $event): EventValidationResult
    {
        $valid = $this->eventValidationService->isEventValid(InnisEvent::fromArray($event->toArray()));

        return $valid ? EventValidationResult::valid() : EventValidationResult::invalid(['Event is invalid according to Innis validation service.']);
    }
}

