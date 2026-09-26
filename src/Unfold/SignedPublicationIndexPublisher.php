<?php

declare(strict_types=1);

namespace App\Unfold;

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

    public function publish(
        array $signedEvent,
        string $publicationCoordinate,
        string $baseEventId,
        ?string $aboutCoordinate,
        array $relayHints,
        bool $updateAboutArticle = true,
    ): array {
        try {
            $event = $this->verifier->fromArray($signedEvent);
            if (!$this->verifier->verify($event)) {
                throw new \InvalidArgumentException('Invalid index signature.');
            }
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid signed index event.', 0, $e);
        }

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