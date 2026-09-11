<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Service\GenericEventProjector;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrClient;
use DecentNewsroom\UnfoldBundle\Contract\PublicationRefreshInterface;
use Psr\Log\LoggerInterface;

final readonly class PublicationRefreshAdapter implements PublicationRefreshInterface
{
    public function __construct(
        private NostrClient $nostrClient,
        private GenericEventProjector $eventProjector,
        private EventIngestionListener $eventIngestionListener,
        private LoggerInterface $logger,
    ) {
    }

    public function refresh(string $coordinate): void
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            $this->logger->warning('Cannot refresh invalid publication coordinate', ['coordinate' => $coordinate]);
            return;
        }

        $decoded = [
            'kind' => (int) $parts[0],
            'pubkey' => strtolower($parts[1]),
            'identifier' => $parts[2],
            'relays' => [],
        ];

        try {
            $root = $this->nostrClient->getEventByNaddr($decoded);
            if ($root === null) {
                return;
            }

            $this->ingest($root);
            $coordinates = [];
            foreach ($root->tags ?? [] as $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 'a' && isset($tag[1])) {
                    $coordinates[] = (string) $tag[1];
                }
            }

            foreach ($this->nostrClient->getEventsByCoordinates($coordinates) as $event) {
                try {
                    $this->ingest($event);
                } catch (\Throwable $exception) {
                    $this->logger->warning('Failed to ingest publication child', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to refresh publication', [
                'coordinate' => $coordinate,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function ingest(object $event): void
    {
        $entity = $this->eventProjector->projectEventFromNostrEvent($event, 'cache-warm');
        $this->eventIngestionListener->processEvent($entity);
    }
}
