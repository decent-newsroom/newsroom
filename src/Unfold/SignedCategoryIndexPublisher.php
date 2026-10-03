<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Service\GenericEventProjector;
use App\Service\Graph\ReferenceParserService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\RelayPublishResult;
use DecentNewsroom\UnfoldBundle\Admin\CategoryContentMutation;
use DecentNewsroom\UnfoldBundle\Config\CategoryReference;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationIndexConflictException;
use DecentNewsroom\UnfoldBundle\Contract\SignedCategoryIndexPublisherInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final readonly class SignedCategoryIndexPublisher implements SignedCategoryIndexPublisherInterface
{
    public function __construct(
        private NostrEventVerifier $verifier,
        private GenericEventProjector $projector,
        private NostrClient $nostrClient,
        private Connection $connection,
        private EventReadGatewayInterface $events,
        private LoggerInterface $logger,
        private ReferenceParserService $referenceParser,
        private ManagerRegistry $managerRegistry,
    ) {}

    public function publish(array $signedEvent, string $publicationCoordinate, string $categoryCoordinate, string $baseEventId, ContentReference $reference, string $action): array
    {
        if (!in_array($action, ['add', 'remove'], true) || preg_match('/^[a-f0-9]{64}$/D', $baseEventId) !== 1) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        if (($signedEvent['kind'] ?? null) !== 30040 || !is_int($signedEvent['created_at'] ?? null)
            || !is_string($signedEvent['content'] ?? null) || !is_string($signedEvent['pubkey'] ?? null)
            || !is_string($signedEvent['id'] ?? null) || !is_string($signedEvent['sig'] ?? null)
            || !is_array($signedEvent['tags'] ?? null)) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        CategoryContentMutation::assertTags($signedEvent['tags']);
        $publicationCoordinate = CategoryReference::fromInput($publicationCoordinate)->coordinate;
        $categoryCoordinate = CategoryReference::fromInput($categoryCoordinate)->coordinate;
        [, $owner] = explode(':', $publicationCoordinate, 3);
        try {
            $event = $this->verifier->fromArray($signedEvent);
            if (!$this->verifier->verify($event)) {
                throw new \InvalidArgumentException('unfold_category.invalid_signature');
            }
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('unfold_category.invalid_signature', 0, $e);
        }
        $candidate = self::fromArray($event->toArray());
        if ($candidate->pubkey !== $owner || !CategoryContentMutation::matchesCategory($candidate, $categoryCoordinate)) {
            throw new \InvalidArgumentException('unfold_category.read_only');
        }
        $projectionAttempted = false;
        try {
            $this->connection->transactional(function () use ($candidate, $event, $publicationCoordinate, $categoryCoordinate, $owner, $baseEventId, $reference, $action, &$projectionAttempted): void {
            // All owner writes lock root before child; the root is never modified.
            $root = $this->current($publicationCoordinate);
            CategoryContentMutation::assertAttached($root, $publicationCoordinate, $categoryCoordinate);
            $child = $this->current($categoryCoordinate);
            if (!CategoryContentMutation::matchesCategory($child, $categoryCoordinate) || strtolower($child->pubkey) !== $owner) {
                throw new \InvalidArgumentException('unfold_category.read_only');
            }
            if ($child->id === $candidate->id) {
                if ($child->tags !== $candidate->tags || $child->content !== $candidate->content || $child->sig !== $candidate->sig
                    || $child->createdAt !== $candidate->createdAt) {
                    throw new \InvalidArgumentException('unfold_category.invalid');
                }
                return;
            }
            if ($child->id !== $baseEventId) {
                throw new PublicationIndexConflictException('unfold_category.stale');
            }
            $tags = CategoryContentMutation::tags($child, $reference, $action);
            if ($tags === $child->tags || $candidate->tags !== $tags || $candidate->content !== $child->content
                || $candidate->createdAt <= $child->createdAt || $candidate->createdAt > time() + 300) {
                throw new \InvalidArgumentException('unfold_category.invalid');
            }
            if ($action === 'add') {
                $leaf = $this->events->findByCoordinate($reference->coordinate, $reference->relayHints);
                if ($leaf === null || !$reference->matches($leaf)) {
                    throw new \InvalidArgumentException('unfold_category.unresolved');
                }
                if (ContentReference::isScoped($leaf)) {
                    throw new \InvalidArgumentException('unfold_category.scoped');
                }
            }
            $projectionAttempted = true;
            $stored = $this->projector->projectEventFromNostrEvent((object) $event->toArray(), 'unfold-category', true);
            $after = $this->connection->fetchOne('SELECT current_event_id FROM current_record WHERE coord = :coordinate', ['coordinate' => $categoryCoordinate]);
            if ($stored->getId() !== $candidate->id || $after !== $candidate->id) {
                throw new PublicationIndexConflictException('unfold_category.projection_failed');
            }
            $expected = count($this->referenceParser->parseFromTagsArray($candidate->id, 30040, $candidate->tags));
            $projected = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM parsed_reference WHERE source_event_id = :id', ['id' => $candidate->id]);
            if ($projected !== $expected) {
                throw new PublicationIndexConflictException('unfold_category.projection_failed');
            }
            });
        } catch (\Throwable $e) {
            // Rollback restores SQL rows but not Doctrine's identity map. Do
            // not let a retry mistake a rolled-back entity for a stored event.
            if ($projectionAttempted) {
                $this->managerRegistry->resetManager();
            }
            throw $e;
        }

        try {
            $results = $this->nostrClient->publishEvent($event, [], 10);
        } catch (\Throwable $e) {
            $this->logger->warning('Category committed locally; relay publication failed', ['event_id' => $candidate->id, 'exception' => $e]);
            $results = [];
        }
        $published = false;
        $complete = $results !== [];
        $report = [];
        foreach ($results as $relay => $result) {
            $ok = RelayPublishResult::isSuccessful($result);
            $published = $published || $ok;
            $complete = $complete && $ok;
            $report[(string) $relay] = $result instanceof RelayPublishResult ? $result->toArray() : (is_array($result) ? $result : ['ok' => $ok]);
        }
        return ['event_id' => $candidate->id, 'local_commit' => true, 'published' => $published, 'relay_complete' => $complete, 'retryable' => !$complete, 'relay_results' => $report];
    }

    private function current(string $coordinate): NostrEvent
    {
        $id = $this->connection->fetchOne('SELECT current_event_id FROM current_record WHERE coord = :coordinate FOR UPDATE', ['coordinate' => $coordinate]);
        if ($id === false) {
            throw new PublicationIndexConflictException('unfold_category.refresh_required');
        }
        $row = $this->connection->fetchAssociative('SELECT id, pubkey, kind, content, tags, created_at, sig FROM event WHERE id = :id', ['id' => $id]);
        if ($row === false) {
            throw new PublicationIndexConflictException('unfold_category.refresh_required');
        }
        if (is_string($row['tags'])) {
            $row['tags'] = json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR);
        }
        return self::fromArray($row);
    }

    private static function fromArray(array $event): NostrEvent
    {
        if (!is_array($event['tags'] ?? null)) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        CategoryContentMutation::assertTags($event['tags']);
        return new NostrEvent($event['id'], $event['pubkey'], (int) $event['kind'], $event['content'], $event['tags'], (int) $event['created_at'], $event['sig']);
    }
}
