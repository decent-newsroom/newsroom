<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventIdCalculatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use Innis\Nostr\Core\Domain\Entity\Event as InnisEvent;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;

final readonly class InnisEventIdCalculator implements EventIdCalculatorInterface
{
    /**
     * @throws InvalidEventException
     */
    public function calculate(NostrEvent $event): EventId
    {
        $innisEvent = InnisEvent::fromArray($event->toArray());

        try {
            return new EventId($innisEvent->calculateId()->toHex());
        } catch (InvalidEventException $e) {
            throw new InvalidEventException(
                message: 'Failed to calculate Nostr event id.',
                previous: $e,
            );
        }
    }
}

