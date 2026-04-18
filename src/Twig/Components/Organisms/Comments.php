<?php

namespace App\Twig\Components\Organisms;

use App\Message\FetchCommentsMessage;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrLinkParser;
use App\Util\Nip22TagParser;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('Organisms:Comments')]
final class Comments
{
    use DefaultActionTrait;

    // Writable prop the browser can set
    #[LiveProp(writable: true)]
    public string $payloadJson = ' '; // { comments, profiles, ... }

    // Live input
    #[LiveProp(writable: false)]
    public string $current;

    #[LiveProp]
    public array $list = [];
    public array $commentLinks = [];
    public array $processedContent = [];
    public array $zapAmounts = [];
    public array $zappers = [];
    public array $authorsMetadata = [];
    /** @var array<string, string[]> comment ID → list of display names being replied to */
    public array $replyingTo = [];
    /** @var array<string, array{pubkey: string, content: string}|null> comment ID → parent comment preview */
    public array $parentPreview = [];
    public bool  $loading = true;

    public function __construct(
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly RedisCacheService $redisCacheService,
        private readonly EventRepository $eventRepository,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Mount the component with the current coordinate.
     *
     * Loads any existing comments straight from the database so the page
     * renders immediately, then dispatches an async message so the worker
     * can refresh from relays and push updates via Mercure.
     */
    public function mount(string $current): void
    {
        $this->current = $current;

        // ── 1. Immediate DB load ──────────────────────────────────────
        try {
            $dbEvents = $this->eventRepository->findCommentsByCoordinate($current);

            if (!empty($dbEvents)) {
                $this->list = array_map(function ($event) {
                    return [
                        'id'         => $event->getId(),
                        'kind'       => $event->getKind(),
                        'pubkey'     => $event->getPubkey(),
                        'content'    => $event->getContent(),
                        'created_at' => $event->getCreatedAt(),
                        'tags'       => $event->getTags(),
                        'sig'        => $event->getSig(),
                    ];
                }, $dbEvents);

                // Hydrate author metadata from cache
                $pubkeys = array_unique(array_filter(array_column($this->list, 'pubkey')));
                $this->authorsMetadata = $this->normalizeMetadata(
                    $this->redisCacheService->getMultipleMetadata($pubkeys)
                );

                $this->parseZaps();
                $this->parseNostrLinks();
                $this->parseReplyMetadata();
            }
        } catch (\Throwable) {
            // DB unavailable – fall through to loading state
        }

        $this->loading = false;

        // ── 2. Async relay refresh (may push Mercure update later) ────
        try {
            $this->bus->dispatch(new FetchCommentsMessage($current));
        } catch (\Throwable) {
            // transport unavailable (Redis down, etc.) – not critical
        }
    }

    #[LiveAction]
    public function loadComments(#[LiveArg] string $payload): void
    {
        $data = json_decode($payload, true);

        // If your handler doesn’t compute zaps/links yet, reuse your helpers here:
        $this->list            = $data['comments'] ?? [];
        if (empty($this->list)) {
            $this->loading = false;
            return;
        }

        $this->authorsMetadata = $data['profiles'] ?? [];

        $this->parseZaps();
        $this->parseNostrLinks();
        $this->parseReplyMetadata();

        $this->loading = false;
    }

    /**
     * Parse Nostr links in comments and store processed content.
     *
     * Inline rendering is handled by Atoms:Content + resolve_nostr_embeds,
     * so we only track processed content here for reference.
     */
    private function parseNostrLinks(): void
    {
        foreach ($this->list as $comment) {
            $content = $comment['content'] ?? '';
            $commentId = $comment['id'] ?? null;

            if (empty($content) || empty($commentId)) {
                continue;
            }

            $this->processedContent[$commentId] = $content;
        }
    }

    /**
     * Parse Zaps to get amounts
     */
    public function parseZaps(): void
    {
        // Use reference (&$comment) so changes to content are saved back to $this->list
        foreach ($this->list as &$comment) {
            $commentId = $comment['id'] ?? null;

            if (empty($commentId)) {
                continue;
            }

            // check if kind is 9735 to get zaps
            if (($comment['kind'] ?? null) !== 9735) {
                continue;
            }

            $tags = $comment['tags'] ?? [];
            if (empty($tags) || !is_array($tags)) {
                continue;
            }

            // 1) Find and decode description JSON
            $descriptionJson = $this->findTagValue($tags, 'description');

            if ($descriptionJson === null) {
                continue; // can't validate sender without description
            }
            $description = json_decode($descriptionJson);

            // 2) If description has content, add it to the comment (now this actually saves!)
            if (!empty($description->content)) {
                $comment['content'] = $description->content;
            }

            // 3) Get amount: prefer explicit 'amount' tag (msat), fallback to BOLT11 invoice parsing
            $amountSats = null;

            $amountMsatStr = $this->findTagValue($description->tags ?? [], 'amount');
            if (is_numeric($amountMsatStr)) {
                // amount in millisats per NIP-57
                $msats = (int) $amountMsatStr;
                if ($msats > 0) {
                    $amountSats = intdiv($msats, 1000);
                }
            }

            if (empty($amountSats)) {
                $bolt11 = $this->findTagValue($tags, 'bolt11');
                if (!empty($bolt11)) {
                    $amountSats = $this->parseBolt11ToSats($bolt11);
                }
            }

            $this->zappers[$commentId] = $this->findTagValue($tags, 'P');
            if ($amountSats !== null && $amountSats > 0) {
                $this->zapAmounts[$commentId] = $amountSats;
            }
        }
        // Unset reference to avoid accidental modification later
        unset($comment);
    }

    // --- Helpers ---

    /**
     * Parse NIP-22 tags to build reply metadata using Nip22TagParser.
     *
     * Only populates "replying to" and parent preview for replies to
     * other comments (parentKind = 1111), not top-level article comments.
     */
    private function parseReplyMetadata(): void
    {
        // Index comments by ID for parent lookups
        $commentsById = [];
        foreach ($this->list as $comment) {
            if (!empty($comment['id'])) {
                $commentsById[$comment['id']] = $comment;
            }
        }

        $neededPubkeys = [];

        foreach ($this->list as $comment) {
            $commentId = $comment['id'] ?? null;
            $tags = $comment['tags'] ?? [];

            if (empty($commentId) || empty($tags) || !is_array($tags)) {
                continue;
            }

            $parsed = Nip22TagParser::parse($tags);

            // Only show reply metadata for replies to other comments
            if ($parsed['parentKind'] !== '1111') {
                continue;
            }

            // 1) "Replying to" from lowercase p-tags, excluding self
            $pTagPubkeys = array_filter(
                $parsed['parentPubkeys'],
                fn(string $pk) => $pk !== ($comment['pubkey'] ?? '')
            );
            if (!empty($pTagPubkeys)) {
                $pTagPubkeys = array_values(array_unique($pTagPubkeys));
                $this->replyingTo[$commentId] = $pTagPubkeys;
                foreach ($pTagPubkeys as $pk) {
                    $neededPubkeys[$pk] = true;
                }
            }

            // 2) Parent comment preview from lowercase e-tag
            $parentId = $parsed['parentEventId'];
            if ($parentId !== null && isset($commentsById[$parentId])) {
                $parent = $commentsById[$parentId];
                $parentPubkey = $parent['pubkey'] ?? '';
                $neededPubkeys[$parentPubkey] = true;
                $this->parentPreview[$commentId] = [
                    'pubkey' => $parentPubkey,
                    'content' => mb_strimwidth($parent['content'] ?? '', 0, 120, '…'),
                ];
            }
        }

        // Hydrate any missing metadata for referenced pubkeys
        $missingPubkeys = array_diff(array_keys($neededPubkeys), array_keys($this->authorsMetadata));
        if (!empty($missingPubkeys)) {
            $extra = $this->normalizeMetadata(
                $this->redisCacheService->getMultipleMetadata(array_values($missingPubkeys))
            );
            $this->authorsMetadata = array_merge($this->authorsMetadata, $extra);
        }

        // Resolve pubkeys to display names
        foreach ($this->replyingTo as $commentId => $pubkeys) {
            $names = [];
            foreach ($pubkeys as $pk) {
                $meta = $this->authorsMetadata[$pk] ?? null;
                $name = $meta->display_name ?? $meta->name ?? null;
                $names[] = $name ?: substr($pk, 0, 8) . '…';
            }
            $this->replyingTo[$commentId] = $names;
        }
    }

    /**
     * Normalize metadata from RedisCacheService to stdClass objects.
     *
     * getMultipleMetadata() may return UserMetadata DTOs, stdClass, or mixed.
     * Templates and name resolution expect stdClass with ->display_name, ->name, etc.
     *
     * @param array<string, mixed> $metadata pubkey => metadata
     * @return array<string, \stdClass|null>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $pubkey => $meta) {
            if ($meta instanceof \App\Dto\UserMetadata) {
                $normalized[$pubkey] = $meta->toStdClass();
            } elseif ($meta instanceof \stdClass) {
                $normalized[$pubkey] = $meta;
            } else {
                $normalized[$pubkey] = null;
            }
        }
        return $normalized;
    }

    /**
     * Find first tag value by key.
     * @param array $tags
     * @param string $key
     * @return string|null
     */
    private function findTagValue(array $tags, string $key): ?string
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            if (($tag[0] ?? null) === $key) {
                return (string) $tag[1];
            }
        }
        return null;
    }

    /**
     * Minimal BOLT11 amount parser to sats from invoice prefix (lnbc...amount[munp]).
     * Returns null if amount is not embedded.
     */
    private function parseBolt11ToSats(string $invoice): ?int
    {
        // Normalize
        $inv = strtolower(trim($invoice));
        // Find the amount section after the hrp prefix (lnbc or lntb etc.)
        // Spec: ln + currency + amount + multiplier. We only need amount+multiplier.
        if (!str_starts_with($inv, 'ln')) {
            return null;
        }
        // Strip prefix up to first digit
        $i = 0;
        while ($i < strlen($inv) && !ctype_digit($inv[$i])) {
            $i++;
        }
        if ($i >= strlen($inv)) {
            return null; // no amount encoded
        }
        // Read numeric part
        $j = $i;
        while ($j < strlen($inv) && ctype_digit($inv[$j])) {
            $j++;
        }
        $numStr = substr($inv, $i, $j - $i);
        if ($numStr === '') {
            return null;
        }
        $amount = (int) $numStr;
        // Multiplier char (optional). If none, amount is in BTC (rare for zaps)
        $mult = $inv[$j] ?? '';
        $sats = null;
        // 1 BTC = 100_000_000 sats
        switch ($mult) {
            case 'm': // milli-btc
                $sats = (int) round($amount * 100_000); // 0.001 BTC = 100_000 sats
                break;
            case 'u': // micro-btc
                $sats = (int) round($amount * 1000); // 0.000001 BTC = 1000 sats
                break;
            case 'n': // nano-btc
                $sats = (int) round($amount * 0.001); // 0.000000001 BTC = 0.001 sats
                break;
            case 'p': // pico-btc
                $sats = (int) round($amount * 0.000001); // 1e-12 BTC = 1e-4 sats
                break;
            default:
                // No multiplier => amount in BTC
                $sats = (int) round($amount * 100_000_000);
                break;
        }
        // Ensure positive and at least 1 sat if rounding produced 0
        return $sats > 0 ? $sats : null;
    }
}
