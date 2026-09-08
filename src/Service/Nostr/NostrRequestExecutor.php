<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use DecentNewsroom\SigningBundle\Contract\CurrentSubjectPubkeyResolverInterface;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Psr\Log\LoggerInterface;

/**
 * Application-level relay request machinery.
 *
 * This class only exposes host-owned request/result contracts. The AMPHP
 * client, relay gateway and wire protocol are implementation details of the
 * pool behind it.
 */
class NostrRequestExecutor
{
    public function __construct(
        private readonly RelayPoolInterface $relayPool,
        private readonly RelaySetFactory $relaySetFactory,
        private readonly LoggerInterface $logger,
        private readonly ?CurrentSubjectPubkeyResolverInterface $currentSubjectPubkeyResolver = null,
    ) {
    }

    /**
     * @param int[] $kinds
     * @param array<string, mixed> $filters
     */
    public function buildRequest(
        array $kinds,
        array $filters = [],
        ?RelaySet $relaySet = null,
        mixed $stopGap = null,
    ): RelayQueryRequest {
        $filter = $filters;
        if ($kinds !== []) {
            $filter['kinds'] = $kinds;
        }

        $request = new RelayQueryRequest(
            $relaySet ?? $this->relaySetFactory->getDefault(),
            [self::normaliseFilterArray($filter)],
        );

        if (is_string($stopGap) && $stopGap !== '') {
            $request->stopOnEventId($stopGap);
        }

        return $request;
    }

    /**
     * Execute a request, preserving the initiating subject for NIP-42.
     *
     * @return array<string, RelayQueryResult>
     */
    public function execute(RelayQueryRequest $request, ?string $pubkey = null, int $gatewayTimeout = 3): array
    {
        $request->requestedBy(
            $pubkey ?? $this->currentSubjectPubkeyResolver?->resolveCurrentSubjectPubkeyHex()
        );
        $request->setGatewayTimeout($gatewayTimeout);

        return $this->relayPool->executeRequest($request);
    }

    /**
     * Process typed relay results into the legacy stdClass event shape used by
     * the domain projectors. No transport/vendor response objects escape.
     *
     * @return array<int, object>
     */
    public function process(array $response, callable $handler): array
    {
        $results = [];

        foreach ($response as $relayUrl => $relayResult) {
            if ($relayResult instanceof RelayQueryResult) {
                if ($relayResult->error !== null) {
                    $this->logger->warning('Relay query failed', [
                        'relay' => $relayUrl,
                        'error' => $relayResult->error,
                    ]);
                }

                foreach ($relayResult->events as $event) {
                    $value = $handler($this->toLegacyEvent($event));
                    if ($value !== null) {
                        $results[] = $value;
                    }
                }
                continue;
            }

            // Compatibility for callers/tests that still provide the old
            // response array while they are being migrated.
            if (!is_array($relayResult)) {
                continue;
            }
            foreach ($relayResult as $item) {
                $event = is_object($item) && isset($item->event) ? $item->event : null;
                if (($item->type ?? null) !== 'EVENT' || $event === null) {
                    continue;
                }
                $value = $handler($event);
                if ($value !== null) {
                    $results[] = $value;
                }
            }
        }

        return $results;
    }

    /**
     * @param int[] $kinds
     * @param array<string, mixed> $filters
     * @return object[]
     */
    public function fetch(
        array $kinds,
        array $filters = [],
        ?RelaySet $relaySet = null,
        ?callable $handler = null,
        ?string $pubkey = null,
        int $gatewayTimeout = 3,
        ?int $directTimeout = null,
    ): array {
        $request = $this->buildRequest($kinds, $filters, $relaySet);
        if ($directTimeout !== null) {
            $request->setTimeout($directTimeout);
        }

        return $this->process(
            $this->execute($request, $pubkey, $gatewayTimeout),
            $handler ?? static fn (object $event): object => $event,
        );
    }

    /**
     * @param int[] $kinds
     * @param array<string, mixed> $filters
     */
    public function fetchFirst(
        array $kinds,
        array $filters,
        RelaySet $primary,
        ?RelaySet $fallback = null,
        ?string $pubkey = null,
        int $gatewayTimeout = 8,
        ?int $directTimeout = null,
    ): ?object {
        $events = $this->fetch($kinds, $filters, $primary, null, $pubkey, $gatewayTimeout, $directTimeout);
        if ($events !== []) {
            return $events[0];
        }

        if ($fallback !== null) {
            $events = $this->fetch($kinds, $filters, $fallback, null, $pubkey, $gatewayTimeout, $directTimeout);

            return $events[0] ?? null;
        }

        return null;
    }

    /**
     * @param int[] $kinds
     * @param array<string, mixed> $extraFilters
     * @return object[]
     */
    public function fetchByTimeRange(
        array $kinds,
        int $from = 0,
        int $to = 0,
        int $limit = 500,
        int $defaultDays = 7,
        ?RelaySet $relaySet = null,
        array $extraFilters = [],
    ): array {
        $to = $to ?: time();
        $from = $from ?: $to - ($defaultDays * 86400);

        return $this->fetch(
            $kinds,
            array_merge($extraFilters, ['since' => $from, 'until' => $to, 'limit' => $limit]),
            $relaySet,
        );
    }

    public static function buildFilterFromArray(array $filter): Filter
    {
        return Filter::fromArray(self::normaliseFilterArray($filter));
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public static function normaliseFilterArray(array $filter): array
    {
        if (isset($filter['tag']) && is_array($filter['tag'])) {
            [$name, $values] = $filter['tag'] + [null, []];
            if (is_string($name) && is_array($values)) {
                $filter[$name[0] === '#' ? $name : '#'.$name] = $values;
            }
            unset($filter['tag']);
        }

        foreach (['e', 'p', 'a', 'd', 't', 'A', 'E'] as $tag) {
            if (isset($filter[$tag]) && !isset($filter['#'.$tag])) {
                $filter['#'.$tag] = $filter[$tag];
                unset($filter[$tag]);
            }
        }

        return $filter;
    }

    private function toLegacyEvent(\Innis\Nostr\Core\Domain\Entity\Event $event): object
    {
        return (object) $event->toArray();
    }

    public function publish(object $event, array $relayUrls, ?string $pubkey = null, int $timeout = 30): array
    {
        return $this->relayPool->publish($event, $relayUrls, $pubkey, $timeout);
    }
}
