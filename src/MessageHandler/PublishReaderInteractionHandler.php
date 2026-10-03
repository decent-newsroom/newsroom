<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PublishReaderInteractionMessage;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\RelayPublishResult;
use App\Unfold\InteractionOutboxStore;
use App\Repository\DeletedEventRepository;
use DecentNewsroom\UnfoldBundle\Contract\InteractionReaderInterface;
use Innis\Nostr\Core\Domain\Entity\Event as NostrEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Delivers one locally-committed reader interaction (comment/reply, like, or
 * repost) to its resolved relay set and records the outcome durably.
 *
 * Relay results are cumulative across attempts: a relay once confirmed ok is
 * never retried again. The outbox row is claimed via a short lease before any
 * network I/O and the lease is always cleared when the attempt is recorded,
 * so a crashed worker cannot hold a row hostage — the recovery command will
 * reclaim it once the lease expires.
 */
#[AsMessageHandler]
final class PublishReaderInteractionHandler
{
    public function __construct(
        private readonly InteractionOutboxStore $outbox,
        private readonly NostrClient $nostrClient,
        private readonly InteractionReaderInterface $reader,
        private readonly LoggerInterface $logger,
        private readonly DeletedEventRepository $deletedEvents,
    ) {
    }

    public function __invoke(PublishReaderInteractionMessage $message): void
    {
        $eventId = $message->getEventId();

        $claimed = $this->outbox->claimForDelivery($eventId, dispatchLease: $message->getDispatchLease());
        if ($claimed === null) {
            // Already resolved (published/partial/failed) or concurrently
            // claimed by another worker — nothing to do, not an error.
            return;
        }

        /** @var array<string, array{ok: bool, message: ?string, latency_ms: ?int}> $cumulative */
        $cumulative = $claimed['relay_results'];
        /** @var list<string> $allRelays */
        $allRelays = $claimed['relays'];
        $attempts = $claimed['attempts'] + 1;
        $maxAttempts = $claimed['max_attempts'] ?: InteractionOutboxStore::DEFAULT_MAX_ATTEMPTS;

        // Mandatory re-resolution of CURRENT local publication membership and
        // public scope, immediately before any relay send. Local acceptance
        // may be stale by the time this queued row is actually delivered: the
        // target leaf may since have been removed from the publication or had
        // its visibility tightened. We deliberately do NOT diff the target's
        // content/revision here (a stable coordinate's comment thread does
        // not need to re-match byte-for-byte) — only membership/public scope
        // is mandatory. A missing/denied target is a terminal failure with
        // no network fallback; it never burns remaining attempts pretending
        // a legitimately-undeliverable row might still succeed later.
        $source = $claimed['signed_event'];
        $deleted = $this->deletedEvents->isSuppressed($eventId, $source['kind'], $source['pubkey'], null, $source['created_at']);
        if ($deleted || $this->reader->target($claimed['publication_coordinate'], $claimed['target_coordinate']) === null) {
            $this->outbox->recordAttempt(
                $eventId,
                InteractionOutboxStore::STATUS_FAILED,
                $cumulative,
                max($attempts, $maxAttempts),
                InteractionOutboxStore::TERMINAL_TARGET_ERROR,
                null,
                $claimed['leased_until'] ?? null,
            );
            $this->logger->error('Reader interaction target no longer resolvable/in scope at delivery time; dropping without network send', [
                'event_id' => $eventId,
            ]);

            return;
        }

        if ($allRelays === []) {
            // No relay could be resolved for this reader/target at acceptance
            // time; no amount of retrying changes that — fail immediately
            // rather than burn the retry budget pretending to try.
            $this->outbox->recordAttempt($eventId, InteractionOutboxStore::STATUS_FAILED, $cumulative, $attempts, 'No relays resolved for delivery', null, $claimed['leased_until'] ?? null);
            $this->logger->error('Reader interaction has no relays to deliver to', ['event_id' => $eventId]);

            return;
        }

        $alreadyOk = array_keys(array_filter($cumulative, static fn (array $r): bool => $r['ok'] === true));
        $pending = array_values(array_diff($allRelays, $alreadyOk));

        if ($pending !== []) {
            try {
                $eventObj = NostrEvent::fromArray($claimed['signed_event']);
                $results = $this->nostrClient->publishEvent($eventObj, $pending);
            } catch (\Throwable $e) {
                $this->logger->warning('Reader interaction relay publish threw', ['event_id' => $eventId, 'error' => $e->getMessage()]);
                $results = array_fill_keys($pending, ['ok' => false, 'message' => $e->getMessage(), 'latency_ms' => null]);
            }

            foreach ($pending as $relay) {
                $result = $results[$relay] ?? ['ok' => false, 'message' => 'No response recorded for relay', 'latency_ms' => null];
                $cumulative[$relay] = [
                    'ok' => RelayPublishResult::isSuccessful($result),
                    'message' => is_array($result) ? ($result['message'] ?? null) : null,
                    'latency_ms' => is_array($result) ? ($result['latency_ms'] ?? null) : null,
                ];
            }
        }

        $okCount = count(array_filter($cumulative, static fn (array $r): bool => $r['ok'] === true));
        $total = count($allRelays);

        $lastError = null;
        foreach ($cumulative as $relay => $result) {
            if ($result['ok'] !== true && $result['message'] !== null) {
                $lastError = sprintf('%s: %s', $relay, $result['message']);
            }
        }

        if ($okCount === $total) {
            $status = InteractionOutboxStore::STATUS_PUBLISHED;
            $nextDelay = null;
            $lastError = null;
        } elseif ($attempts >= $maxAttempts) {
            // Terminal — retries exhausted. Never success-shaped: partial
            // (some relays ok) or failed (none ok) are both non-"published".
            $status = $okCount > 0 ? InteractionOutboxStore::STATUS_PARTIAL : InteractionOutboxStore::STATUS_FAILED;
            $nextDelay = null;
        } else {
            $status = InteractionOutboxStore::STATUS_QUEUED;
            $nextDelay = InteractionOutboxStore::backoffSeconds($attempts);
        }

        $this->outbox->recordAttempt($eventId, $status, $cumulative, $attempts, $lastError, $nextDelay, $claimed['leased_until'] ?? null);

        $this->logger->info('Reader interaction relay delivery attempt recorded', [
            'event_id' => $eventId,
            'status' => $status,
            'ok_relays' => $okCount,
            'total_relays' => $total,
            'attempt' => $attempts,
        ]);
    }
}
