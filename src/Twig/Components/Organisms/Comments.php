<?php

namespace App\Twig\Components\Organisms;

use App\Message\FetchCommentsMessage;
use App\Service\NostrLinkParser;
use App\Service\RedisCacheService;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('Organisms:Comments')]
final class Comments
{
    use DefaultActionTrait;

    // Live input
    #[LiveProp(writable: false)]
    public string $current;

    // same fields you used before (the template will read from the payload)
    public array $list = [];
    public array $commentLinks = [];
    public array $processedContent = [];
    public array $zapAmounts = [];
    public array $zappers = [];
    public array $authorsMetadata = [];
    public bool  $loading = true;

    public function __construct(
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly RedisCacheService $redisCacheService,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Mount the component with the current coordinate
     */
    public function mount(string $current): void
    {
        $this->current = $current;
        $this->loading = true;

        // Kick off (async) fetch to populate cache + publish Mercure
        try {
            $this->bus->dispatch(new FetchCommentsMessage($current));
        } catch (ExceptionInterface) {
            // Doing nothing for now
        }
    }

    /** Expose a view model to the template; keeps all parsing server-side */
    public function getPayload(): array
    {
        // Uses the helper we added earlier: getCommentsPayload($coordinate)
        $payload = $this->redisCacheService->getCommentsPayload($this->current) ?? [
            'comments'      => [],
            'profiles'      => [],
            'zappers'       => [],
            'zapAmounts'    => [],
            'commentLinks'  => [],
        ];

        // If your handler doesn’t compute zaps/links yet, reuse your helpers here:
        $this->list            = $payload['comments'];
        $this->authorsMetadata = $payload['profiles'] ?? [];

        $this->parseZaps();         // your existing method – fills $zapAmounts & $zappers
        $this->parseNostrLinks();   // your existing method – fills $commentLinks & $processedContent

        return [
            'list'            => $this->list,
            'authorsMetadata' => $this->authorsMetadata,
            'zappers'         => $this->zappers,
            'zapAmounts'      => $this->zapAmounts,
            'commentLinks'    => $this->commentLinks,
            'loading'         => false,
        ];
    }

    /**
     * Parse Nostr links in comments for client-side loading
     */
    private function parseNostrLinks(): void
    {
        foreach ($this->list as $comment) {
            $content = $comment->content ?? '';
            if (empty($content)) {
                continue;
            }

            // Store the original content
            $this->processedContent[$comment->id] = $content;

            // Parse the content for Nostr links
            $links = $this->nostrLinkParser->parseLinks($content);

            if (!empty($links)) {
                // Save the links for the client-side to fetch
                $this->commentLinks[$comment->id] = $links;
            }
        }
    }

    /**
     * Parse Zaps to get amounts
     */
    public function parseZaps(): void
    {
        foreach ($this->list as $comment) {
            // check if kind is 9735 to get zaps
            if ($comment->kind !== 9735) {
                continue;
            }

            $tags = $comment->tags ?? [];
            if (empty($tags) || !is_array($tags)) {
                continue;
            }

            // 1) Find and decode description JSON
            $descriptionJson = $this->findTagValue($tags, 'description');

            if ($descriptionJson === null) {
                continue; // can't validate sender without description
            }
            $description = json_decode($descriptionJson);

            // 2) If description has content, add it to the comment
            if (!empty($description->content)) {
                $comment->content = $description->content;
            }

            // 3) Get amount: prefer explicit 'amount' tag (msat), fallback to BOLT11 invoice parsing
            $amountSats = null;

            $amountMsatStr = $this->findTagValue($description->tags, 'amount');
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

            $this->zappers[$comment->id] = $this->findTagValue($tags, 'P');
            if ($amountSats !== null && $amountSats > 0) {
                $this->zapAmounts[$comment->id] = $amountSats;
            }
        }
    }

    // --- Helpers ---

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
