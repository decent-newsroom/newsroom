<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Message\PublishReaderInteractionMessage;
use App\MessageHandler\PublishReaderInteractionHandler;
use App\Service\Nostr\NostrClient;
use App\Unfold\InteractionOutboxStore;
use App\Repository\DeletedEventRepository;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\InteractionReaderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Delivery/backoff behaviour of the reader-interaction relay broadcast
 * handler: cumulative relay-result merging, bounded-retry status
 * transitions (queued -> partial/failed/published), claim/lease race
 * protection via the outbox store, and the mandatory pre-send re-resolution
 * of current local publication membership/public scope.
 */
final class PublishReaderInteractionDeliveryTest extends TestCase
{
    private const EVENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PUBLICATION_COORDINATE = '30040:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:root';
    private const TARGET_COORDINATE = '30023:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:post-1';

    public function testNoOpWhenRowAlreadyResolvedOrLeasedByAnotherWorker(): void
    {
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn(null);
        $outbox->expects(self::never())->method('recordAttempt');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testAllRelaysSuccessfulTransitionsToPublished(): void
    {
        $claimed = $this->claimed(relays: ['wss://one.example', 'wss://two.example'], cumulative: [], attempts: 0);
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID,
            InteractionOutboxStore::STATUS_PUBLISHED,
            self::callback(static function (array $results): bool {
                self::assertTrue($results['wss://one.example']['ok']);
                self::assertTrue($results['wss://two.example']['ok']);
                return true;
            }),
            1,
            null,
            null,
        );
        $client = $this->createMock(NostrClient::class);
        $client->method('publishEvent')->willReturn([
            'wss://one.example' => ['ok' => true, 'latency_ms' => 10],
            'wss://two.example' => ['ok' => true, 'latency_ms' => 12],
        ]);

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testAlreadyConfirmedRelayIsNeverRetried(): void
    {
        $claimed = $this->claimed(
            relays: ['wss://one.example', 'wss://two.example'],
            cumulative: ['wss://one.example' => ['ok' => true, 'message' => null, 'latency_ms' => 5]],
            attempts: 1,
        );
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID, InteractionOutboxStore::STATUS_PUBLISHED, self::anything(), 2, null, null,
        );
        $client = $this->createMock(NostrClient::class);
        // Only the still-pending relay is sent to the network layer.
        $client->expects(self::once())->method('publishEvent')->with(self::anything(), ['wss://two.example'])
            ->willReturn(['wss://two.example' => ['ok' => true, 'latency_ms' => 8]]);

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testFailureWithRemainingBudgetStaysQueuedWithBackoff(): void
    {
        $claimed = $this->claimed(relays: ['wss://one.example'], cumulative: [], attempts: 0);
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID,
            InteractionOutboxStore::STATUS_QUEUED,
            self::anything(),
            1,
            self::stringContains('wss://one.example'),
            InteractionOutboxStore::backoffSeconds(1),
        );
        $client = $this->createMock(NostrClient::class);
        $client->method('publishEvent')->willReturn(['wss://one.example' => ['ok' => false, 'message' => 'timeout']]);

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testExhaustedRetriesWithNoSuccessfulRelayBecomesFailed(): void
    {
        $claimed = $this->claimed(relays: ['wss://one.example'], cumulative: [], attempts: 2, maxAttempts: 3);
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID, InteractionOutboxStore::STATUS_FAILED, self::anything(), 3, self::isType('string'), null,
        );
        $client = $this->createMock(NostrClient::class);
        $client->method('publishEvent')->willReturn(['wss://one.example' => ['ok' => false, 'message' => 'refused']]);

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testExhaustedRetriesWithSomeSuccessfulRelaysBecomesPartialNeverSuccessShaped(): void
    {
        $claimed = $this->claimed(
            relays: ['wss://one.example', 'wss://two.example'],
            cumulative: ['wss://one.example' => ['ok' => true, 'message' => null, 'latency_ms' => 5]],
            attempts: 2,
            maxAttempts: 3,
        );
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID, InteractionOutboxStore::STATUS_PARTIAL, self::anything(), 3, self::isType('string'), null,
        );
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('publishEvent')->with(self::anything(), ['wss://two.example'])
            ->willReturn(['wss://two.example' => ['ok' => false, 'message' => 'refused']]);

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testNoResolvedRelaysFailsImmediatelyWithoutBurningRetryBudget(): void
    {
        $claimed = $this->claimed(relays: [], cumulative: [], attempts: 0);
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID, InteractionOutboxStore::STATUS_FAILED, [], 1, self::isType('string'), null,
        );
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testNetworkExceptionDuringPublishIsRecordedAsFailureNotUncaught(): void
    {
        $claimed = $this->claimed(relays: ['wss://one.example'], cumulative: [], attempts: 0);
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID,
            InteractionOutboxStore::STATUS_QUEUED,
            self::callback(static function (array $results): bool {
                self::assertFalse($results['wss://one.example']['ok']);
                return true;
            }),
            1,
            self::isType('string'),
            InteractionOutboxStore::backoffSeconds(1),
        );
        $client = $this->createMock(NostrClient::class);
        $client->method('publishEvent')->willThrowException(new \RuntimeException('connection refused'));

        $this->handler($outbox, $client)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testMissingOrDeniedCurrentTargetIsTerminalFailureWithNoNetworkSend(): void
    {
        $claimed = $this->claimed(relays: ['wss://one.example'], cumulative: [], attempts: 0, maxAttempts: 3);
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->expects(self::once())->method('recordAttempt')->with(
            self::EVENT_ID,
            InteractionOutboxStore::STATUS_FAILED,
            [],
            3, // forced to max attempts — terminal, never left mid-retry
            self::isType('string'),
            null,
        );
        $reader = $this->createMock(InteractionReaderInterface::class);
        $reader->expects(self::once())->method('target')
            ->with(self::PUBLICATION_COORDINATE, self::TARGET_COORDINATE)
            ->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');

        $this->handler($outbox, $client, $reader)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    public function testCurrentTargetIsReResolvedBeforeEveryDeliveryAttemptEvenWhenRelaysArePending(): void
    {
        $claimed = $this->claimed(relays: ['wss://one.example'], cumulative: [], attempts: 1, maxAttempts: 3);
        $outbox = $this->createMock(InteractionOutboxStore::class);
        $outbox->method('claimForDelivery')->willReturn($claimed);
        $outbox->method('recordAttempt');
        $reader = $this->createMock(InteractionReaderInterface::class);
        $reader->expects(self::once())->method('target')
            ->with(self::PUBLICATION_COORDINATE, self::TARGET_COORDINATE)
            ->willReturn($this->fixtureTarget());
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('publishEvent')
            ->willReturn(['wss://one.example' => ['ok' => true, 'latency_ms' => 5]]);

        $this->handler($outbox, $client, $reader)(new PublishReaderInteractionMessage(self::EVENT_ID));
    }

    /** @param list<string> $relays */
    private function claimed(array $relays, array $cumulative, int $attempts, int $maxAttempts = 3): array
    {
        return [
            'event_id' => self::EVENT_ID,
            'publication_coordinate' => self::PUBLICATION_COORDINATE,
            'target_coordinate' => self::TARGET_COORDINATE,
            'signed_event' => ['id' => self::EVENT_ID, 'pubkey' => str_repeat('c', 64), 'created_at' => 1_700_000_000, 'kind' => 7, 'tags' => [], 'content' => '+', 'sig' => str_repeat('f', 128)],
            'relays' => $relays,
            'relay_results' => $cumulative,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
        ];
    }

    private function fixtureTarget(): InteractionTarget
    {
        $post = new PostData(
            slug: 'post-1',
            title: 'Post',
            summary: '',
            content: 'Body',
            image: null,
            publishedAt: 1_700_000_000,
            pubkey: str_repeat('b', 64),
            coordinate: self::TARGET_COORDINATE,
            kind: 30023,
            tags: [['d', 'post-1']],
            eventId: str_repeat('e', 64),
        );

        return new InteractionTarget(self::PUBLICATION_COORDINATE, $post);
    }

    private function handler(InteractionOutboxStore $outbox, NostrClient $client, ?InteractionReaderInterface $reader = null): PublishReaderInteractionHandler
    {
        if ($reader === null) {
            $reader = $this->createMock(InteractionReaderInterface::class);
            $reader->method('target')->willReturn($this->fixtureTarget());
        }

        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn(false);
        return new PublishReaderInteractionHandler($outbox, $client, $reader, new NullLogger(), $deletions);
    }
}
