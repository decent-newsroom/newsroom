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

    /** Cached recipient lud16 from kind 0 metadata. */
    #[LiveProp]
    public ?string $recipientLud16 = null;

    /** Cached recipient lud06 (bech32 LNURL from kind 0 metadata). */
    #[LiveProp]
    public ?string $recipientLud06 = null;

    #[LiveProp]
    public string $btnClass = '';

    #[LiveProp]
    public bool $iconOnly = false;

    // UI state
    #[LiveProp(writable: true)]
    public bool $open = false;

    /** idle | targets_loading | select | lightning_input | loading | invoice | payto | error */
    #[LiveProp(writable: true)]
    public string $phase = 'idle';

    /** Stable key of the currently selected target. */
    #[LiveProp(writable: true)]
    public string $selectedTargetKey = '';

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

    /**
     * Resolved payment targets snapshot persisted across Live requests.
     *
     * @var array<int, array{type:string,authority:string,uri:string,href:string,key:string,recognized:bool,label:string,symbol:string,shortLabel:string,extra:array<int,string>}>
     */
    #[LiveProp]
    public array $resolvedTargets = [];

    /** Whether the target snapshot has been populated for this component state. */
    #[LiveProp]
    public bool $targetsHydrated = false;

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
     * @return array<int, array{type:string,authority:string,uri:string,href:string,key:string,recognized:bool,label:string,symbol:string,shortLabel:string,extra:array<int,string>}>
     */
    public function getTargets(): array
    {
        if ($this->targetsHydrated) {
            return $this->resolvedTargets;
        }

        $pubkeyHex = $this->resolvePubkeyHex();
        if ($pubkeyHex === null) {
            $this->resolvedTargets = [];
            $this->targetsHydrated = true;

            return [];
        }

        $targets = $this->paymentTargetService->getForPubkey($pubkeyHex);
        $targets = $this->withKind0ZapTargets($targets);

        $this->resolvedTargets = array_map(fn(PaymentTarget $t) => $t->toArray(), $targets);
        $this->targetsHydrated = true;

        return $this->resolvedTargets;
    }

    /**
     * Group resolved targets by payment type for a cleaner selection UI.
     *
     * @return array<int, array{type:string,label:string,symbol:string,recognized:bool,targets:array<int, array{type:string,authority:string,uri:string,href:string,key:string,recognized:bool,label:string,symbol:string,shortLabel:string,extra:array<int,string>}>}>
     */
    public function getTargetGroups(): array
    {
        $groups = [];

        foreach ($this->getTargets() as $target) {
            $type = $target['type'];

            if (!isset($groups[$type])) {
                $groups[$type] = [
                    'type' => $type,
                    'label' => $target['label'],
                    'symbol' => $target['symbol'],
                    'recognized' => $target['recognized'],
                    'targets' => [],
                ];
            }

            $groups[$type]['targets'][] = $target;
        }

        return array_values($groups);
    }

    /**
     * Resolve the currently selected target or null when nothing is selected.
     */
    public function getSelectedTarget(): ?array
    {
        if ($this->selectedTargetKey === '') {
            return null;
        }

        foreach ($this->getTargets() as $target) {
            if ($target['key'] === $this->selectedTargetKey) {
                return $target;
            }
        }

        return null;
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
            // Relay-fetched targets are not always persisted yet. Expose the
            // in-memory snapshot so admins can still debug what the user sees.
            if ($this->targetsHydrated && $this->resolvedTargets !== []) {
                $payload = [
                    'source' => 'live_component_snapshot',
                    'kind' => 10133,
                    'pubkey' => $pubkeyHex,
                    'tags' => array_map(static fn(array $target): array => array_merge([
                        'payto',
                        (string) $target['type'],
                        (string) $target['authority'],
                    ], (array) ($target['extra'] ?? [])), $this->resolvedTargets),
                ];

                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                return $json !== false ? $json : null;
            }

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
        $this->phase = 'targets_loading';
        $this->selectedTargetKey = '';
        $this->resolvedTargets = [];
        $this->targetsHydrated = false;
        $this->resetTransient();
    }

    #[LiveAction]
    public function loadTargets(): void
    {
        $pubkeyHex = $this->resolvePubkeyHex();

        if ($pubkeyHex === null) {
            $this->resolvedTargets = [];
            $this->targetsHydrated = true;
            $this->error = 'Could not resolve recipient pubkey.';
            $this->phase = 'error';

            return;
        }

        try {
            $targets = $this->paymentTargetService->getFreshForPubkey($pubkeyHex);
        } catch (
            \Throwable $e
        ) {
            $this->resolvedTargets = [];
            $this->targetsHydrated = true;
            $this->error = 'Could not load payment targets right now. Please try again.';
            $this->phase = 'error';

            $this->logger->warning('Tip targets load failed', [
                'recipient' => $this->recipientPubkey,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $targets = $this->withKind0ZapTargets($targets);

        $this->resolvedTargets = array_map(fn(PaymentTarget $t) => $t->toArray(), $targets);
        $this->targetsHydrated = true;
        $this->phase = 'select';
    }

    #[LiveAction]
    public function closeDialog(): void
    {
        $this->open = false;
        $this->phase = 'idle';
        $this->selectedTargetKey = '';
        $this->resolvedTargets = [];
        $this->targetsHydrated = false;
        $this->amount = 21;
        $this->comment = '';
        $this->resetTransient();
    }

    #[LiveAction]
    public function selectTarget(#[LiveArg] ?string $targetKey = null): void
    {
        $targets = $this->getTargets();

        if ($targetKey === null) {
            $this->selectedTargetKey = '';
            $this->resetTransient();
            $this->error = 'Invalid payment target.';
            $this->phase = 'error';

            return;
        }

        $target = null;
        foreach ($targets as $candidate) {
            if ($candidate['key'] === $targetKey) {
                $target = $candidate;
                break;
            }
        }

        if ($target === null) {
            $this->selectedTargetKey = '';
            $this->resetTransient();
            $this->error = 'Invalid payment target.';
            $this->phase = 'error';

            return;
        }

        $this->selectedTargetKey = $targetKey;
        $this->resetTransient();

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
        $this->selectedTargetKey = '';
        $this->resetTransient();
    }

    #[LiveAction]
    public function createLightningInvoice(): void
    {
        $target = $this->getSelectedTarget();
        if ($target === null || $target['type'] !== 'lightning') {
            $this->error = 'No lightning target selected.';
            $this->phase = 'error';
            return;
        }

        $lightningTarget = trim((string) $target['authority']);
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
            $isBech32Lnurl = str_starts_with(strtolower($lightningTarget), 'lnurl1');
            $lnurlInfo = $this->lnurlResolver->resolve(
                $isBech32Lnurl ? null : $lightningTarget,
                $isBech32Lnurl ? $lightningTarget : null,
            );

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
                lnurl: $lnurlInfo->bech32 ?? $lightningTarget,
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

    /**
     * @param PaymentTarget[] $targets
     * @return PaymentTarget[]
     */
    private function withKind0ZapTargets(array $targets): array
    {
        $zapTags = [];

        $lud16 = trim((string) ($this->recipientLud16 ?? ''));
        if ($lud16 !== '') {
            $zapTags[] = ['payto', 'lightning', $lud16, 'kind0', 'lud16'];
        }

        $lud06 = trim((string) ($this->recipientLud06 ?? ''));
        if ($lud06 !== '') {
            $zapTags[] = ['payto', 'lightning', $lud06, 'kind0', 'lud06'];
        }

        if ($zapTags === []) {
            return $targets;
        }

        $syntheticTargets = $this->paymentTargetService->parseTags($zapTags);

        return $this->deduplicateTargets(array_merge($targets, $syntheticTargets));
    }

    /**
     * @param PaymentTarget[] $targets
     * @return PaymentTarget[]
     */
    private function deduplicateTargets(array $targets): array
    {
        $seen = [];
        $deduped = [];

        foreach ($targets as $target) {
            $key = $target->type . '|' . $target->authority;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $target;
        }

        return $deduped;
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




