<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Service\LNURLResolver;
use App\Service\NostrSigner;
use App\Service\QRGenerator;
use Psr\Log\LoggerInterface;
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
    }

    /**
     * Create a zap invoice
     */
    #[LiveAction]
    public function createInvoice(): void
    {
        $this->error = '';
        $this->phase = 'loading';

        try {
            // Validate amount
            if ($this->amount <= 0) {
                throw new \RuntimeException('Amount must be greater than 0');
            }

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
                relays: [], // Optional: could add user's preferred relays
                zapSplits: $this->zapSplits
            );

            // URL-encode the zap request for the callback
            $nostrParam = urlencode($zapRequestJson);

            // Request invoice from LNURL callback
            $this->bolt11 = $this->lnurlResolver->requestInvoice(
                callback: $lnurlInfo->callback,
                amountMillisats: $amountMillisats,
                nostrEvent: $zapRequestJson,
                lnurl: $lnurlInfo->bech32
            );

            // Generate QR code
            $this->qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($this->bolt11), 280);

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
}

