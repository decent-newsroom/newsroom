<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Service;

use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

final class CollectingEventHandler implements EventHandlerInterface
{
    /** @var list<array<string,mixed>> */
    private array $events = [];
    private bool $eose = false;
    private ?string $closedMessage = null;
    private ?string $notice = null;

    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        $this->events[] = $event->toArray();
    }

    public function handleEose(SubscriptionId $subscriptionId): void
    {
        $this->eose = true;
    }

    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
        $this->closedMessage = $message;
        $this->eose = true;
    }

    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
        $this->notice = $message;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    public function isDone(): bool
    {
        return $this->eose;
    }

    public function error(): ?string
    {
        return $this->closedMessage;
    }

    public function notice(): ?string
    {
        return $this->notice;
    }
}