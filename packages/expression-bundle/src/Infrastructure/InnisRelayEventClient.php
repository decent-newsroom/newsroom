<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Infrastructure;

use Amp\TimeoutException;
use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Contract\RelayEventClientInterface;
use DecentNewsroom\ExpressionBundle\Model\ArrayEvent;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\delay;

final class InnisRelayEventClient implements RelayEventClientInterface
{
    public function __construct(
        private readonly NostrClientInterface $client,
        private readonly float $timeoutSeconds = 5.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function fetch(array $kinds, array $filter, array $relayUrls = [], ?string $pubkey = null): array
    {
        $filterData = $filter;
        if ($kinds !== []) {
            $filterData['kinds'] = $kinds;
        }

        $tags = [];
        foreach ($filterData as $key => $values) {
            if (is_string($key) && str_starts_with($key, '#')) {
                $tags[substr($key, 1)] = (array) $values;
            }
        }

        $coreFilter = new Filter(
            ids: isset($filterData['ids']) ? (array) $filterData['ids'] : null,
            authors: isset($filterData['authors']) ? (array) $filterData['authors'] : null,
            kinds: array_map(
                static fn(int $kind): EventKind => EventKind::fromInt($kind),
                array_map('intval', (array) ($filterData['kinds'] ?? [])),
            ) ?: null,
            tags: $tags ?: null,
            since: isset($filterData['since']) ? \Innis\Nostr\Core\Domain\ValueObject\Timestamp::fromInt((int) $filterData['since']) : null,
            until: isset($filterData['until']) ? \Innis\Nostr\Core\Domain\ValueObject\Timestamp::fromInt((int) $filterData['until']) : null,
            limit: isset($filterData['limit']) ? (int) $filterData['limit'] : null,
            search: isset($filterData['search']) ? (string) $filterData['search'] : null,
        );

        $events = [];
        $remaining = 0;
        $handler = new class($events, $remaining) implements EventHandlerInterface {
            /** @param EventInterface[] $events */
            public function __construct(
                private array &$events,
                private int &$remaining,
            ) {}

            public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
            {
                $this->events[] = ArrayEvent::fromArray($event->toArray());
            }

            public function handleEose(SubscriptionId $subscriptionId): void
            {
                --$this->remaining;
            }

            public function handleClosed(SubscriptionId $subscriptionId, string $message): void {}
            public function handleNotice(RelayUrl $relayUrl, string $message): void {}
        };

        foreach (array_values(array_unique($relayUrls)) as $relayUrl) {
            $relay = RelayUrl::fromString($relayUrl);
            if ($relay === null) {
                continue;
            }
            $this->client->connect($relay);
            ++$remaining;
            $this->client->subscribe($relay, $coreFilter, $handler);
        }

        if ($remaining === 0) {
            return [];
        }

        try {
            delay($this->timeoutSeconds);
        } catch (TimeoutException $e) {
            $this->logger->debug('Nostr relay query timed out', ['error' => $e->getMessage()]);
        }

        return $events;
    }
}
