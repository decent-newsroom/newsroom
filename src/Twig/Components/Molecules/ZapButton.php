<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Service\Cache\RedisCacheService;
use App\Service\LNURLResolver;
use App\Service\Nostr\NostrSigner;
use App\Service\QRGenerator;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Minimalist NIP-57 Zap Button Component
 *
 * Provides a simple UI to zap Nostr users via their Lightning Address
 * without requiring any third-party relay or LNbits backend.
 */
#[AsLiveComponent]
final class ZapButton
{
    use DefaultActionTrait;

    // Props: Recipient info (passed from parent)
    #[LiveProp]
    public string $recipientPubkey = '';

    #[LiveProp]
    public ?string $recipientLud16 = null;

    #[LiveProp]
    public ?string $recipientLud06 = null;

    /**
     * Zap splits configuration
     * Array of ['recipient' => hex pubkey, 'relay' => url, 'weight' => int]
     */
    #[LiveProp]
    public array $zapSplits = [];

    // UI state props (internal)
    #[LiveProp(writable: true)]
    public bool $open = false;

    #[LiveProp(writable: true)]
    public string $phase = 'idle'; // idle, input, loading, invoice, error

    #[LiveProp(writable: true)]
    public int $amount = 21; // Amount in sats (default 21)

    #[LiveProp(writable: true)]
    public string $comment = '';

    #[LiveProp]
    public string $error = '';

    #[LiveProp]
    public string $bolt11 = '';

    #[LiveProp]
    public string $qrSvg = '';

    // Multiple invoices support for zap splits
    #[LiveProp(writable: true)]
    public array $invoices = []; // Array of ['recipient' => ..., 'bolt11' => ..., 'qrSvg' => ..., 'amount' => ..., 'paid' => bool]

    #[LiveProp(writable: true)]
    public int $currentInvoiceIndex = 0;

    // LNURL info (stored after resolution)
    #[LiveProp]
    public int $minSendable = 1000; // millisats

    #[LiveProp]
    public int $maxSendable = 11000000000; // millisats (11M sats default)

    public function __construct(
        private readonly LNURLResolver $lnurlResolver,
        private readonly NostrSigner $nostrSigner,
        private readonly QRGenerator $qrGenerator,
        private readonly LoggerInterface $logger,
        private readonly RedisCacheService $redisCacheService,
    ) {}

    /**
     * Open the zap dialog
     */
    #[LiveAction]
    public function openDialog(): void
    {
        if (!$this->recipientLud16 && !$this->recipientLud06) {
            $this->error = 'Recipient has no Lightning Address configured';
            $this->phase = 'error';
            $this->open = true;
            return;
        }

        $this->open = true;
        $this->phase = 'input';
        $this->error = '';
        $this->bolt11 = '';
        $this->qrSvg = '';
        $this->invoices = [];
        $this->currentInvoiceIndex = 0;
    }

    /**
     * Close the dialog and reset state
     */
    #[LiveAction]
    public function closeDialog(): void
    {
        $this->open = false;
        $this->phase = 'idle';
        $this->error = '';
        $this->bolt11 = '';
        $this->qrSvg = '';
        $this->comment = '';
        $this->amount = 21;
        $this->invoices = [];
        $this->currentInvoiceIndex = 0;
    }

    /**
     * Create zap invoice(s) - creates multiple invoices when zap splits are configured
     */
    #[LiveAction]
    public function createInvoice(): void
    {
        $this->error = '';
        $this->phase = 'loading';
        $this->invoices = [];
        $this->currentInvoiceIndex = 0;

        try {
            // Validate amount
            if ($this->amount <= 0) {
                throw new \RuntimeException('Amount must be greater than 0');
            }

            // If we have zap splits, create an invoice for each recipient
            if (!empty($this->zapSplits)) {
                $this->createSplitInvoices();
            } else {
                $this->createSingleInvoice();
            }

            $this->phase = 'invoice';

        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->phase = 'error';
            $this->logger->error('Zap invoice creation failed', [
                'recipient' => $this->recipientPubkey,
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->error = 'Could not reach Lightning endpoint. Please try again.';
            $this->phase = 'error';
            $this->logger->error('Zap invoice creation failed with unexpected error', [
                'recipient' => $this->recipientPubkey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Create a single invoice (no splits)
     */
    private function createSingleInvoice(): void
    {
        // Resolve LNURL
        $lnurlInfo = $this->lnurlResolver->resolve($this->recipientLud16, $this->recipientLud06);

        // Store min/max for validation
        $this->minSendable = $lnurlInfo->minSendable;
        $this->maxSendable = $lnurlInfo->maxSendable;

        // Validate NIP-57 support
        if (!$lnurlInfo->allowsNostr) {
            throw new \RuntimeException('Recipient does not support Nostr zaps (allowsNostr not enabled)');
        }

        if (!$lnurlInfo->nostrPubkey) {
            throw new \RuntimeException('Recipient has not configured a Nostr pubkey for zaps');
        }

        // Convert sats to millisats
        $amountMillisats = $this->amount * 1000;

        // Validate amount against limits
        if ($amountMillisats < $lnurlInfo->minSendable) {
            $minSats = (int) ceil($lnurlInfo->minSendable / 1000);
            throw new \RuntimeException("Amount too low. Minimum: {$minSats} sats");
        }

        if ($amountMillisats > $lnurlInfo->maxSendable) {
            $maxSats = (int) floor($lnurlInfo->maxSendable / 1000);
            throw new \RuntimeException("Amount too high. Maximum: {$maxSats} sats");
        }

        // Build and sign NIP-57 zap request (kind 9734)
        $zapRequestJson = $this->nostrSigner->buildZapRequest(
            recipientPubkey: $this->recipientPubkey,
            amountMillisats: $amountMillisats,
            lnurl: $lnurlInfo->bech32 ?? ($this->recipientLud16 ?? $this->recipientLud06 ?? ''),
            comment: $this->comment,
            relays: [],
            zapSplits: []
        );

        // Request invoice from LNURL callback
        $this->bolt11 = $this->lnurlResolver->requestInvoice(
            callback: $lnurlInfo->callback,
            amountMillisats: $amountMillisats,
            nostrEvent: $zapRequestJson,
            lnurl: $lnurlInfo->bech32
        );

        // Generate QR code
        $this->qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($this->bolt11), 280);
    }

    /**
     * Create multiple invoices based on zap splits
     */
    private function createSplitInvoices(): void
    {
        // Calculate total weight
        $totalWeight = array_sum(array_map(fn($s) => $s['weight'] ?? 1, $this->zapSplits));

        if ($totalWeight <= 0) {
            $totalWeight = count($this->zapSplits); // Equal split if no weights
        }

        $totalAmountSats = $this->amount;
        $invoices = [];

        foreach ($this->zapSplits as $index => $split) {
            $weight = $split['weight'] ?? 1;
            $recipientPubkey = $split['recipient'];

            // Calculate this recipient's share
            $sharePercent = ($weight / $totalWeight) * 100;
            $shareSats = (int) round(($weight / $totalWeight) * $totalAmountSats);

            // Skip if amount rounds to 0
            if ($shareSats < 1) {
                continue;
            }

            $amountMillisats = $shareSats * 1000;

            try {
                // We need to get the lud16 for this pubkey - for now we'll use the relay hint
                // In a real implementation, you'd look up the user's lightning address
                // For now, we'll note that the user needs to have their lightning address configured

                $invoices[] = [
                    'recipient' => $recipientPubkey,
                    'weight' => $weight,
                    'sharePercent' => round($sharePercent, 1),
                    'amount' => $shareSats,
                    'bolt11' => null,
                    'qrSvg' => null,
                    'error' => null,
                    'paid' => false,
                ];
            } catch (\Exception $e) {
                $this->logger->error('Failed to prepare invoice for split recipient', [
                    'recipient' => $recipientPubkey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($invoices)) {
            throw new \RuntimeException('Could not prepare any invoices for split recipients');
        }

        $this->invoices = $invoices;

        // Create invoice for the first recipient
        $this->createInvoiceForSplitRecipient(0);
    }

    /**
     * Create invoice for a specific split recipient by index
     */
    #[LiveAction]
    public function createInvoiceForSplitRecipient(int $index = 0): void
    {
        if ($index < 0 || $index >= count($this->invoices)) {
            return;
        }

        $this->currentInvoiceIndex = $index;
        $invoice = $this->invoices[$index];

        // Invoice already created
        if ($invoice['bolt11'] && $invoice['bolt11'] !== 'PENDING') {
            $this->bolt11 = $invoice['bolt11'];
            $this->qrSvg = $invoice['qrSvg'];
            $this->phase = 'invoice';
            return;
        }

        $this->phase = 'loading';

        try {
            $recipientIdent = $invoice['recipient'];
            $amountMillisats = $invoice['amount'] * 1000;

            // Convert npub to hex if needed
            $key = new Key();
            if (str_starts_with($recipientIdent, 'npub1')) {
                $recipientPubkey = $key->convertToHex($recipientIdent);
            } else {
                $recipientPubkey = $recipientIdent;
            }

            // Look up the recipient's lightning address from their metadata
            $recipientMeta = $this->redisCacheService->getMetadata($recipientPubkey);
            $recipientLud16 = $recipientMeta->lud16 ?? null;

            // Handle case where lud16 might be an array
            if (is_array($recipientLud16)) {
                $recipientLud16 = $recipientLud16[0] ?? null;
            }

            if (!$recipientLud16) {
                throw new \RuntimeException('Recipient has no Lightning Address (lud16) configured');
            }

            // Resolve LNURL for this recipient
            $lnurlInfo = $this->lnurlResolver->resolve($recipientLud16, null);

            // Validate NIP-57 support
            if (!$lnurlInfo->allowsNostr) {
                throw new \RuntimeException('Recipient does not support Nostr zaps');
            }

            // Validate amount against limits
            if ($amountMillisats < $lnurlInfo->minSendable) {
                $minSats = (int) ceil($lnurlInfo->minSendable / 1000);
                throw new \RuntimeException("Amount too low for this recipient. Minimum: {$minSats} sats");
            }

            // Build zap request for this specific recipient
            $zapRequestJson = $this->nostrSigner->buildZapRequest(
                recipientPubkey: $recipientPubkey,
                amountMillisats: $amountMillisats,
                lnurl: $lnurlInfo->bech32 ?? $recipientLud16,
                comment: $this->comment,
                relays: [],
                zapSplits: []
            );

            // Request invoice from LNURL callback
            $bolt11 = $this->lnurlResolver->requestInvoice(
                callback: $lnurlInfo->callback,
                amountMillisats: $amountMillisats,
                nostrEvent: $zapRequestJson,
                lnurl: $lnurlInfo->bech32
            );

            // Generate QR code
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($bolt11), 280);

            // Update the invoice in the array
            $this->invoices[$index]['bolt11'] = $bolt11;
            $this->invoices[$index]['qrSvg'] = $qrSvg;
            $this->invoices[$index]['error'] = null;

            $this->bolt11 = $bolt11;
            $this->qrSvg = $qrSvg;
            $this->phase = 'invoice';

        } catch (\Exception $e) {
            $this->invoices[$index]['error'] = $e->getMessage();
            $this->bolt11 = '';
            $this->qrSvg = '';
            $this->phase = 'invoice';
            $this->logger->error('Failed to create invoice for split recipient', [
                'recipient' => $invoice['recipient'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Navigate to next invoice in split payment
     */
    #[LiveAction]
    public function nextInvoice(): void
    {
        if ($this->currentInvoiceIndex < count($this->invoices) - 1) {
            $this->createInvoiceForSplitRecipient($this->currentInvoiceIndex + 1);
        }
    }

    /**
     * Navigate to previous invoice in split payment
     */
    #[LiveAction]
    public function previousInvoice(): void
    {
        if ($this->currentInvoiceIndex > 0) {
            $this->createInvoiceForSplitRecipient($this->currentInvoiceIndex - 1);
        }
    }

    /**
     * Mark current invoice as paid
     */
    #[LiveAction]
    public function markAsPaid(): void
    {
        if (isset($this->invoices[$this->currentInvoiceIndex])) {
            $this->invoices[$this->currentInvoiceIndex]['paid'] = true;
        }
    }
}

