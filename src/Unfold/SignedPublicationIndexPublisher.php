<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Enum\KindsEnum;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\RelayPublishResult;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Contract\PublicationIndexConflictException;
use DecentNewsroom\UnfoldBundle\Contract\SignedPublicationIndexPublisherInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class SignedPublicationIndexPublisher implements SignedPublicationIndexPublisherInterface
{
    public function __construct(
        private NostrEventVerifier $verifier,
        private GenericEventProjector $projector,
        private NostrClient $nostrClient,
        private PublicationSettingsManager $settings,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    public function publishRoot(array $signedEvent, string $publicationCoordinate): array
    {
        $event = $this->verifiedEvent($signedEvent);
        $eventId = $event->getId()->toHex();

        if ($event->getKind()->toInt() !== KindsEnum::PUBLICATION_INDEX->value) {
            throw new \InvalidArgumentException('Signed root index must be a kind 30040 event.');
        }
        if ($this->eventCoordinate($event) !== $publicationCoordinate) {
            throw new \InvalidArgumentException('Signed root index does not match the publication coordinate.');
        }

        $this->connection->transactional(function () use ($event, $eventId, $publicationCoordinate): void {
            // A root can be initialized only once. Lock an existing current
            // record so concurrent initialization cannot replace it silently.
            $current = $this->connection->fetchOne(
                'SELECT current_event_id FROM current_record WHERE coord = :coordinate FOR UPDATE',
                ['coordinate' => $publicationCoordinate],
            );
            if ($current !== false) {
                throw new PublicationIndexConflictException('Magazine index already exists.');
            }

            $stored = $this->projector->projectEventFromNostrEvent((object) $event->toArray(), 'unfold-root');
            $after = $this->connection->fetchOne(
                'SELECT current_event_id FROM current_record WHERE coord = :coordinate',
                ['coordinate' => $publicationCoordinate],
            );
            if ($stored->getId() !== $eventId || $after !== $eventId) {
                throw new PublicationIndexConflictException('Signed magazine index did not become current.');
            }
        });

        return $this->publishCommittedEvent($event, $eventId);
    }

    public function publish(
        array $signedEvent,
        string $publicationCoordinate,
        string $baseEventId,
        ?string $aboutCoordinate,
        array $relayHints,
        bool $updateAboutArticle = true,
    ): array {
        $event = $this->verifiedEvent($signedEvent);
        $eventId = $event->getId()->toHex();

        $this->connection->transactional(function () use (
            $event, $eventId, $publicationCoordinate, $baseEventId, $aboutCoordinate, $relayHints, $updateAboutArticle
        ): void {
            // Lock this coordinate while checking the base revision. A second
            // settings editor must not silently overwrite a newer root event.
            $current = $this->connection->fetchOne(
                'SELECT current_event_id FROM current_record WHERE coord = :coordinate FOR UPDATE',
                ['coordinate' => $publicationCoordinate],
            );
            if ($current !== false && $current !== $baseEventId && $current !== $eventId) {
                throw new PublicationIndexConflictException('Magazine index changed. Reload settings and try again.');
            }

            // The generic projector updates current_record, parsed_reference,
            // and the article-in-publication reverse index.
            $stored = $this->projector->projectEventFromNostrEvent((object) $event->toArray(), 'unfold-about');
            $after = $this->connection->fetchOne(
                'SELECT current_event_id FROM current_record WHERE coord = :coordinate',
                ['coordinate' => $publicationCoordinate],
            );
            if ($stored->getId() !== $eventId || $after !== $eventId) {
                throw new PublicationIndexConflictException('Signed magazine index did not become current.');
            }

            if ($updateAboutArticle) {
                $currentSettings = $this->settings->get($publicationCoordinate);
                $this->settings->savePresentation(
                    $publicationCoordinate,
                    $currentSettings->theme,
                    $currentSettings->footerLinks,
                    $aboutCoordinate,
                    $relayHints,
                    true,
                );
            }
        });

        return $this->publishCommittedEvent($event, $eventId);
    }

    private function verifiedEvent(array $signedEvent): \Innis\Nostr\Core\Domain\Entity\Event
    {
        try {
            $event = $this->verifier->fromArray($signedEvent);
            if (!$this->verifier->verify($event)) {
                throw new \InvalidArgumentException('Invalid index signature.');
            }

            return $event;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid signed index event.', 0, $e);
        }
    }

    private function eventCoordinate(\Innis\Nostr\Core\Domain\Entity\Event $event): ?string
    {
        $identifier = null;
        foreach ($event->getTags()->toArray() as $tag) {
            if (($tag[0] ?? null) !== 'd') {
                continue;
            }
            if (!isset($tag[1]) || $identifier !== null) {
                return null;
            }
            $identifier = $tag[1];
        }

        return $identifier === null ? null : sprintf(
            '%d:%s:%s',
            $event->getKind()->toInt(),
            $event->getPubkey()->toHex(),
            $identifier,
        );
    }

    /**
     * @return array{event_id: string, published: bool, relay_results: array<string, mixed>}
     */
    private function publishCommittedEvent(\Innis\Nostr\Core\Domain\Entity\Event $event, string $eventId): array
    {
        // Network publication runs only after the index and settings commit.
        // A relay failure leaves a locally committed event that can be retried.
        try {
            $relayResults = $this->nostrClient->publishEvent($event, [], 10);
        } catch (\Throwable $e) {
            $this->logger->warning('Signed About index relay publication threw an exception', [
                'event_id' => $eventId,
                'exception' => $e,
            ]);
            $relayResults = [];
        }
        $published = false;
        $report = [];
        foreach ($relayResults as $relay => $result) {
            $ok = RelayPublishResult::isSuccessful($result);
            $published = $published || $ok;
            $report[(string) $relay] = $result instanceof RelayPublishResult
                ? $result->toArray()
                : (is_array($result) ? $result : ['ok' => $ok]);
        }
        if (!$published) {
            $this->logger->warning('Signed About index saved locally but relay publication did not succeed', [
                'event_id' => $eventId,
                'results' => $relayResults,
            ]);
        }

        return [
            'event_id' => $eventId,
            'published' => $published,
            'relay_results' => $report,
        ];
    }
}