<?php

declare(strict_types=1);

namespace App\Service\Nostr\Projector;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\MonitoredRelayRepository;
use App\Repository\RelayMonitorRepository;
use App\Repository\TrustedRelayMonitorRepository;
use App\Service\Nostr\RelayHealthStore;
use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Projects NIP-66 relay discovery events (kinds 10166 + 30166) into the
 * database after they have been persisted to the `event` table by
 * {@see \App\Service\GenericEventProjector}.
 *
 * Only events from **trusted monitor pubkeys** (rows in `trusted_relay_monitor`)
 * are projected. Untrusted events are silently dropped to prevent pollution.
 */
class RelayDiscoveryEventProjector
{
    public function __construct(
        private readonly MonitoredRelayRepository $monitoredRelayRepository,
        private readonly RelayMonitorRepository $relayMonitorRepository,
        private readonly TrustedRelayMonitorRepository $trustedRelayMonitorRepository,
        private readonly RelayHealthStore $healthStore,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Called by GenericEventProjector after the event has been flushed.
     * Handles kinds 10166 and 30166; ignores everything else.
     */
    public function onProjected(Event $event): void
    {
        $kind = $event->getKind();

        if ($kind === KindsEnum::RELAY_MONITOR_ANNOUNCEMENT->value) {
            $this->projectMonitorAnnouncement($event);
            return;
        }

        if ($kind === KindsEnum::RELAY_DISCOVERY->value) {
            $this->projectRelayDiscovery($event);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Kind 10166 — relay monitor self-announcement.
     * Stored in `relay_monitor` regardless of trust; trust is a read-time
     * gate for *projecting measurements*, not for storing the announcement itself.
     */
    private function projectMonitorAnnouncement(Event $event): void
    {
        $tags = $event->getTags() ?? [];
        $freq = null;
        $monitoredRelays = [];
        $checks = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0])) continue;
            match ($tag[0]) {
                'frequency' => $freq = isset($tag[1]) ? (int) $tag[1] : null,
                'r'         => isset($tag[1]) ? $monitoredRelays[] = RelayUrlNormalizer::normalize($tag[1]) : null,
                'k'         => isset($tag[1]) ? $checks[] = $tag[1] : null,
                default     => null,
            };
        }

        $this->relayMonitorRepository->upsert($event->getPubkey(), $event->getId(), $event->getCreatedAt(), [
            'frequency_seconds' => $freq,
            'monitored_relays'  => $monitoredRelays,
            'checks'            => $checks ?: null,
        ]);

        $this->logger->debug('NIP-66: projected relay monitor announcement', [
            'pubkey' => substr($event->getPubkey(), 0, 16) . '…',
        ]);
    }

    /**
     * Kind 30166 — relay monitoring / liveness event.
     * Only projected for trusted monitor pubkeys.
     */
    private function projectRelayDiscovery(Event $event): void
    {
        $pubkey = $event->getPubkey();

        if (!$this->trustedRelayMonitorRepository->isTrusted($pubkey)) {
            $this->logger->debug('NIP-66: ignoring 30166 from untrusted monitor', [
                'pubkey' => substr($pubkey, 0, 16) . '…',
            ]);
            return;
        }

        $tags = $event->getTags() ?? [];
        $relayUrl = null;
        $data = [
            'rtt_open_ms'    => null,
            'rtt_read_ms'    => null,
            'rtt_write_ms'   => null,
            'accepted_kinds' => [],
            'supported_nips' => [],
            'requirements'   => [],
            'topics'         => [],
            'geo'            => [],
        ];

        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0])) continue;
            $v = $tag[1] ?? null;
            match ($tag[0]) {
                'd'         => $relayUrl = $v,
                'rtt-open'  => $v !== null ? $data['rtt_open_ms']  = (int) $v : null,
                'rtt-read'  => $v !== null ? $data['rtt_read_ms']  = (int) $v : null,
                'rtt-write' => $v !== null ? $data['rtt_write_ms'] = (int) $v : null,
                'k'         => $v !== null ? $data['accepted_kinds'][] = (int) $v : null,
                'N'         => $v !== null ? $data['supported_nips'][] = (int) $v : null,
                'R'         => $v !== null ? $data['requirements'][] = $v : null,
                'T'         => $v !== null ? $data['topics'][] = $v : null,
                'g'         => $v !== null ? $data['geo'][] = $v : null,
                default     => null,
            };
        }

        if ($relayUrl === null || $relayUrl === '') {
            $this->logger->debug('NIP-66: skipping 30166 without d tag', ['event' => $event->getId()]);
            return;
        }

        $data['topics'] = $data['topics'] ?: null;
        $data['geo']    = $data['geo'] ?: null;

        $this->monitoredRelayRepository->upsertObservation(
            $pubkey,
            $relayUrl,
            $data,
            $event->getId(),
            $event->getCreatedAt(),
        );

        // Feed RTT measurements into the health store for real-time scoring.
        if ($data['rtt_open_ms'] !== null) {
            $this->healthStore->recordMonitorObservation(
                RelayUrlNormalizer::normalize($relayUrl),
                $data['rtt_open_ms'],
                $data['rtt_read_ms'],
                $data['rtt_write_ms'],
                $event->getCreatedAt(),
                $pubkey,
            );
        }

        $this->logger->debug('NIP-66: projected relay discovery', [
            'relay'   => $relayUrl,
            'monitor' => substr($pubkey, 0, 16) . '…',
            'rtt'     => $data['rtt_open_ms'],
        ]);
    }
}

