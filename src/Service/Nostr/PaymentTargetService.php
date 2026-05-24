<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Dto\PaymentTarget;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\RelayPurpose;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;

/**
 * NIP-A3 (kind 10133) — `payto:` Payment Targets.
 *
 * Parses a user's latest kind 10133 event into a list of normalized
 * {@see PaymentTarget} entries that the UI uses for tip / payment buttons.
 *
 * The `payto` tag format is:
 *   ["payto", "<type>", "<authority>", "<optional_extra_1>", ...]
 *
 * - `type` is lowercased and validated against `[a-z0-9-]+`.
 * - `authority` is kept verbatim (NIP-A3 says it is already URL-safe).
 * - Tags with invalid `type` or empty `authority` are dropped silently.
 * - Duplicates ((type, authority) pair) are de-duplicated keeping the first.
 *
 * Recognized types come with a friendly label / short label / symbol used
 * for rendering. Unknown types are still returned with a generic label,
 * so clients can still render them per NIP-A3.
 */
final class PaymentTargetService
{
    /**
     * Display metadata for the recommended RFC-8905 / NIP-A3 payment types.
     *
     * Keyed by lowercase type. Each entry: [label, shortLabel, symbol].
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    private const RECOGNIZED = [
        'bitcoin'   => ['Bitcoin',           'BTC',     '₿'],
        'cashme'    => ['Cash App',          'Cash App','$'],
        'ethereum'  => ['Ethereum',          'ETH',     'Ξ'],
        'lightning' => ['Lightning Network', 'LBTC',    '⚡'],
        'monero'    => ['Monero',            'XMR',     'ɱ'],
        'nano'      => ['Nano',              'XNO',     'Ӿ'],
        'revolut'   => ['Revolut',           'Revolut', ''],
        'venmo'     => ['Venmo',             'Venmo',   '$'],
    ];

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ?NostrRequestExecutor $requestExecutor = null,
        private readonly ?RelaySetFactory $relaySetFactory = null,
        private readonly ?UserRelayListService $userRelayListService = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Fetch payment targets via a targeted kind 10133 relay lookup.
     *
     * Falls back to the persisted DB snapshot when relay lookup is unavailable
     * or fails.
     */
    public function getFreshForPubkey(string $pubkeyHex): array
    {
        if ($this->requestExecutor === null || $this->relaySetFactory === null || $this->userRelayListService === null) {
            return $this->getForPubkey($pubkeyHex);
        }

        try {
            $relayUrls = $this->userRelayListService->getRelaysForUser($pubkeyHex, RelayPurpose::USER);
            $relaySet = $this->relaySetFactory->fromUrls($relayUrls);

            $events = $this->requestExecutor->fetch(
                kinds: [KindsEnum::PAYMENT_TARGETS->value],
                filters: [
                    'authors' => [$pubkeyHex],
                    'limit' => 1,
                ],
                relaySet: $relaySet,
                pubkey: $pubkeyHex,
                gatewayTimeout: 10,
            );

            if (!empty($events)) {
                return $this->parseRelayEvent($events[0]);
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('PaymentTargetService: targeted kind 10133 lookup failed, using DB snapshot', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }

        return $this->getForPubkey($pubkeyHex);
    }

    /**
     * Fetch and parse the latest kind 10133 event for a user.
     *
     * @return PaymentTarget[]
     */
    public function getForPubkey(string $pubkeyHex): array
    {
        $event = $this->getLatestEventForPubkey($pubkeyHex);

        if ($event === null) {
            return [];
        }

        return $this->parseEvent($event);
    }

    /**
     * Fetch the latest kind 10133 event for a user.
     */
    public function getLatestEventForPubkey(string $pubkeyHex): ?Event
    {
        return $this->eventRepository->findLatestByPubkeyAndKind(
            $pubkeyHex,
            KindsEnum::PAYMENT_TARGETS->value,
        );
    }

    /**
     * Parse a kind 10133 Event entity into PaymentTarget DTOs.
     *
     * @return PaymentTarget[]
     */
    public function parseEvent(Event $event): array
    {
        return $this->parseTags($event->getTags());
    }

    /**
     * Parse a relay event object into PaymentTarget DTOs.
     *
     * @return PaymentTarget[]
     */
    public function parseRelayEvent(object $event): array
    {
        $tags = is_array($event->tags ?? null) ? $event->tags : [];
        return $this->parseTags($this->normalizeTags($tags));
    }

    /**
     * Parse a raw tag array (as found on a Nostr event) into PaymentTarget DTOs.
     *
     * @param array<int, array<int, string>> $tags
     * @return PaymentTarget[]
     */
    public function parseTags(array $tags): array
    {
        $targets = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 3 || ($tag[0] ?? null) !== 'payto') {
                continue;
            }

            $type      = strtolower(trim((string) $tag[1]));
            $authority = trim((string) $tag[2]);

            if ($type === '' || $authority === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9-]+$/', $type)) {
                continue;
            }

            $dedupeKey = $type . '|' . $authority;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $extra = [];
            for ($i = 3, $n = count($tag); $i < $n; $i++) {
                $extra[] = (string) $tag[$i];
            }

            $meta = self::RECOGNIZED[$type] ?? null;
            $recognized = $meta !== null;
            $label      = $meta[0] ?? ucfirst($type);
            $short      = $meta[1] ?? $type;
            $symbol     = $meta[2] ?? '';

            $targets[] = new PaymentTarget(
                type:       $type,
                authority:  $authority,
                recognized: $recognized,
                label:      $label,
                symbol:     $symbol,
                shortLabel: $short,
                extra:      $extra,
            );
        }

        return $targets;
    }

    /**
     * @return array<string, array{label:string,shortLabel:string,symbol:string}>
     */
    public static function recognizedTypes(): array
    {
        $out = [];
        foreach (self::RECOGNIZED as $type => [$label, $short, $symbol]) {
            $out[$type] = ['label' => $label, 'shortLabel' => $short, 'symbol' => $symbol];
        }
        return $out;
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, array<int, string>>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if ($tag instanceof \stdClass) {
                $tag = (array) $tag;
            }
            if (!is_array($tag)) {
                continue;
            }

            $normalizedTag = [];
            foreach ($tag as $value) {
                $normalizedTag[] = (string) $value;
            }

            $normalized[] = $normalizedTag;
        }

        return $normalized;
    }
}

