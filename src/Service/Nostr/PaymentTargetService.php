<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Dto\PaymentTarget;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;

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
    ) {}

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
}

