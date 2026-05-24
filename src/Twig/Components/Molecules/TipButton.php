<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Dto\PaymentTarget;
use App\Service\Cache\RedisCacheService;
use App\Service\LNURLResolver;
use App\Service\Nostr\NostrSigner;
use App\Service\Nostr\PaymentTargetService;
use App\Service\QRGenerator;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * NIP-A3 Tip Button — pick a payment method from kind 10133 payment targets.
 *
 * Lists every `payto` target the recipient has published. When the user picks
 * one:
 *  - `lightning` flows through the existing NIP-57 zap pipeline
 *    (LNURL → zap request → BOLT11 invoice + QR).
 *  - Any other recognized or unknown type renders the `payto://` URI as a
 *    clickable link plus a scannable QR so wallets / external payment apps can
 *    consume it directly.
 *
 * This is intentionally separate from {@see ZapButton}: zaps require a
 * Lightning address (lud16/lud06) on the profile, while tips work for any
 * payment system the author has declared in their NIP-A3 event.
 */
#[AsLiveComponent]
final class TipButton
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $recipientPubkey = '';

    /** Cached recipient lud16 (used when no lightning payto target exists). */
    #[LiveProp]
    public ?string $recipientLud16 = null;

    // UI state
    #[LiveProp(writable: true)]
    public bool $open = false;

    /** idle | select | lightning_input | loading | invoice | payto | error */
    #[LiveProp(writable: true)]
    public string $phase = 'idle';

    /** Index into the resolved targets array. */
    #[LiveProp(writable: true)]
    public int $selectedIndex = -1;

    // Lightning sub-flow state (mirrors ZapButton).
    #[LiveProp(writable: true)]
    public int $amount = 21;

    #[LiveProp(writable: true)]
    public string $comment = '';

    #[LiveProp]
    public string $bolt11 = '';

    #[LiveProp]
    public string $qrSvg = '';

    // Generic payto display state
    #[LiveProp]
    public string $paytoUri = '';

    #[LiveProp]
    public string $paytoQrSvg = '';

    #[LiveProp]
    public string $error = '';

    /** Per-request memoization of resolved targets. */
    private ?array $resolvedTargets = null;

    public function __construct(
        private readonly PaymentTargetService $paymentTargetService,
        private readonly LNURLResolver $lnurlResolver,
        private readonly NostrSigner $nostrSigner,
        private readonly QRGenerator $qrGenerator,
        private readonly RedisCacheService $redisCacheService,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'relay_registry.project_relays')]
        private readonly array $projectRelays = [],
    ) {}

    /**
     * @return array<int, array{type:string,authority:string,uri:string,recognized:bool,label:string,symbol:string,shortLabel:string,extra:array<int,string>}>
     */
    public function getTargets(): array
    {
        if ($this->resolvedTargets !== null) {
            return $this->resolvedTargets;
        }

        $pubkeyHex = $this->resolvePubkeyHex();
        if ($pubkeyHex === null) {
            return $this->resolvedTargets = [];
        }

        $targets = $this->paymentTargetService->getForPubkey($pubkeyHex);

        // If the author has a lud16 in their kind 0 but no `lightning` payto
        // target, surface it as a virtual lightning target so tipping still
        // works out of the box for users that have not adopted NIP-A3 yet.
        $hasLightning = false;
        foreach ($targets as $t) {
            if ($t->isLightning()) {
                $hasLightning = true;
                break;
            }
        }
        if (!$hasLightning && $this->recipientLud16) {
            $synthetic = $this->paymentTargetService->parseTags([
                ['payto', 'lightning', $this->recipientLud16],
            ]);
            $targets = array_merge($synthetic, $targets);
        }

        return $this->resolvedTargets = array_map(fn(PaymentTarget $t) => $t->toArray(), $targets);
    }

    /**
     * Admin-only debug payload of the source kind 10133 event.
     */
    public function getDebugTargetEventPreview(): ?string
    {
        $pubkeyHex = $this->resolvePubkeyHex();
        if ($pubkeyHex === null) {
            return null;
        }

        $event = $this->paymentTargetService->getLatestEventForPubkey($pubkeyHex);
        if ($event === null) {
            return null;
        }

        $payload = [
            'id' => $event->getId(),
            'kind' => $event->getKind(),
            'pubkey' => $event->getPubkey(),
            'created_at' => $event->getCreatedAt(),
            'content' => $event->getContent(),
            'tags' => $event->getTags(),
            'sig' => $event->getSig(),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : null;
    }

    #[LiveAction]
    public function openDialog(): void
    {
        $this->open = true;
        $this->phase = 'select';
        $this->resetTransient();
    }

    #[LiveAction]
    public function closeDialog(): void
    {
        $this->open = false;
        $this->phase = 'idle';
        $this->selectedIndex = -1;
        $this->amount = 21;
        $this->comment = '';
        $this->resetTransient();
    }

    #[LiveAction]
    public function selectTarget(#[LiveArg] ?int $index = null): void
    {
        $targets = $this->getTargets();

        if ($index === null || !isset($targets[$index])) {
            $this->selectedIndex = -1;
            $this->resetTransient();
            $this->error = 'Invalid payment target.';
            $this->phase = 'error';

            return;
        }

        $this->selectedIndex = $index;
        $this->resetTransient();
        $target = $targets[$index];

        if ($target['type'] === 'lightning') {
            $this->phase = 'lightning_input';
            return;
        }

        $this->paytoUri = $target['uri'];
        $this->paytoQrSvg = $this->qrGenerator->svg($this->paytoUri, 280);
        $this->phase = 'payto';
    }

    #[LiveAction]
    public function backToSelect(): void
    {
        $this->phase = 'select';
        $this->selectedIndex = -1;
        $this->resetTransient();
    }

    #[LiveAction]
    public function createLightningInvoice(): void
    {
        $targets = $this->getTargets();
        if (!isset($targets[$this->selectedIndex]) || $targets[$this->selectedIndex]['type'] !== 'lightning') {
            $this->error = 'No lightning target selected.';
            $this->phase = 'error';
            return;
        }

        $lud16 = $targets[$this->selectedIndex]['authority'];
        $pubkeyHex = $this->resolvePubkeyHex();

        if ($pubkeyHex === null) {
            $this->error = 'Could not resolve recipient pubkey.';
            $this->phase = 'error';
            return;
        }

        if ($this->amount <= 0) {
            $this->error = 'Amount must be greater than 0.';
            $this->phase = 'error';
            return;
        }

        $this->phase = 'loading';

        try {
            $lnurlInfo = $this->lnurlResolver->resolve($lud16, null);

            if (!$lnurlInfo->allowsNostr) {
                throw new \RuntimeException('Recipient does not support Nostr zaps. Tip will not be receipted on Nostr.');
            }

            $amountMillisats = $this->amount * 1000;

            if ($amountMillisats < $lnurlInfo->minSendable) {
                $minSats = (int) ceil($lnurlInfo->minSendable / 1000);
                throw new \RuntimeException("Amount too low. Minimum: {$minSats} sats");
            }
            if ($amountMillisats > $lnurlInfo->maxSendable) {
                $maxSats = (int) floor($lnurlInfo->maxSendable / 1000);
                throw new \RuntimeException("Amount too high. Maximum: {$maxSats} sats");
            }

            $zapRequestJson = $this->nostrSigner->buildZapRequest(
                recipientPubkey: $pubkeyHex,
                amountMillisats: $amountMillisats,
                lnurl: $lnurlInfo->bech32 ?? $lud16,
                comment: $this->comment,
                relays: $this->projectRelays,
                zapSplits: []
            );

            $this->bolt11 = $this->lnurlResolver->requestInvoice(
                callback: $lnurlInfo->callback,
                amountMillisats: $amountMillisats,
                nostrEvent: $zapRequestJson,
                lnurl: $lnurlInfo->bech32
            );

            $this->qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($this->bolt11), 280);
            $this->phase = 'invoice';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->phase = 'error';
        } catch (\Throwable $e) {
            $this->error = 'Could not reach Lightning endpoint. Please try again.';
            $this->phase = 'error';
            $this->logger->error('Tip invoice creation failed', [
                'recipient' => $this->recipientPubkey,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function resetTransient(): void
    {
        $this->bolt11 = '';
        $this->qrSvg = '';
        $this->paytoUri = '';
        $this->paytoQrSvg = '';
        $this->error = '';
    }

    private function resolvePubkeyHex(): ?string
    {
        if ($this->recipientPubkey === '') {
            return null;
        }
        if (str_starts_with($this->recipientPubkey, 'npub1')) {
            try {
                return NostrKeyUtil::npubToHex($this->recipientPubkey);
            } catch (\Throwable) {
                return null;
            }
        }
        return $this->recipientPubkey;
    }
}





