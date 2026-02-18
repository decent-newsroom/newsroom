<?php

namespace App\UnfoldBundle\Controller;

use App\Service\Cache\RedisCacheService;
use App\Service\LNURLResolver;
use App\Service\Nostr\NostrSigner;
use App\Service\QRGenerator;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * API endpoints for zap invoice generation in Unfold sites
 */
#[Route('/unfold/api/zap', name: 'unfold_api_zap_')]
class ZapApiController extends AbstractController
{
    private $defaultRelay = 'wss://relay.decentnewsroom.com'; // Default relay for NIP-57 zap requests
    public function __construct(
        private readonly LNURLResolver $lnurlResolver,
        private readonly NostrSigner $nostrSigner,
        private readonly QRGenerator $qrGenerator,
        private readonly RedisCacheService $redisCacheService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create a single invoice (no splits)
     */
    #[Route('/invoice', name: 'create_invoice', methods: ['POST'])]
    public function createInvoice(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $recipientPubkey = $data['pubkey'] ?? '';
        $recipientLud16 = $data['lud16'] ?? null;
        $recipientLud06 = $data['lud06'] ?? null;
        $amount = (int) ($data['amount'] ?? 0);
        $comment = $data['comment'] ?? '';

        // Validation
        if (empty($recipientPubkey)) {
            return new JsonResponse(['error' => 'Recipient pubkey is required'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($recipientLud16) && empty($recipientLud06)) {
            return new JsonResponse(['error' => 'Lightning address (lud16 or lud06) is required'], Response::HTTP_BAD_REQUEST);
        }

        if ($amount <= 0) {
            return new JsonResponse(['error' => 'Amount must be greater than 0'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Handle lud16/lud06 as arrays (take first element if array)
            if (is_array($recipientLud16)) {
                $recipientLud16 = !empty($recipientLud16) ? $recipientLud16[0] : null;
            }
            if (is_array($recipientLud06)) {
                $recipientLud06 = !empty($recipientLud06) ? $recipientLud06[0] : null;
            }

            // Resolve LNURL
            $lnurlInfo = $this->lnurlResolver->resolve($recipientLud16, $recipientLud06);

            // Validate NIP-57 support
            if (!$lnurlInfo->allowsNostr) {
                return new JsonResponse(['error' => 'Recipient does not support Nostr zaps'], Response::HTTP_BAD_REQUEST);
            }

            if (!$lnurlInfo->nostrPubkey) {
                return new JsonResponse(['error' => 'No Nostr pubkey in LNURL response'], Response::HTTP_BAD_REQUEST);
            }

            $amountMillisats = $amount * 1000;

            // Build NIP-57 zap request with at least one relay
            $zapRequestJson = $this->nostrSigner->buildZapRequest(
                recipientPubkey: $recipientPubkey,
                amountMillisats: $amountMillisats,
                lnurl: $lnurlInfo->bech32 ?? ($recipientLud16 ?? $recipientLud06 ?? ''),
                comment: $comment,
                relays: [$this->defaultRelay], // Include default relay for NIP-57 compliance
                zapSplits: []
            );

            // Request invoice
            $bolt11 = $this->lnurlResolver->requestInvoice(
                callback: $lnurlInfo->callback,
                amountMillisats: $amountMillisats,
                nostrEvent: $zapRequestJson,
                lnurl: $lnurlInfo->bech32
            );

            // Generate QR code
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($bolt11), 280);

            return new JsonResponse([
                'success' => true,
                'bolt11' => $bolt11,
                'qrSvg' => $qrSvg,
                'amount' => $amount,
            ]);

        } catch (\RuntimeException $e) {
            $this->logger->warning('Zap invoice creation failed', [
                'recipient' => $recipientPubkey,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error creating zap invoice', [
                'recipient' => $recipientPubkey,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Could not reach Lightning endpoint. Please try again.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create multiple invoices for zap splits
     */
    #[Route('/invoice-split', name: 'create_invoice_split', methods: ['POST'])]
    public function createInvoiceSplit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $zapSplits = $data['zapSplits'] ?? [];
        $totalAmount = (int) ($data['amount'] ?? 0);
        $comment = $data['comment'] ?? '';

        // Validation
        if (empty($zapSplits) || !is_array($zapSplits)) {
            return new JsonResponse(['error' => 'Zap splits array is required'], Response::HTTP_BAD_REQUEST);
        }

        if ($totalAmount <= 0) {
            return new JsonResponse(['error' => 'Amount must be greater than 0'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Calculate total weight
            $totalWeight = array_sum(array_map(fn($s) => $s['weight'] ?? 1, $zapSplits));
            if ($totalWeight <= 0) {
                $totalWeight = count($zapSplits);
            }

            $invoices = [];
            $key = new Key();

            foreach ($zapSplits as $index => $split) {
                $recipientIdent = $split['recipient'] ?? '';
                $weight = $split['weight'] ?? 1;

                if (empty($recipientIdent)) {
                    continue;
                }

                // Convert npub to hex if needed
                if (str_starts_with($recipientIdent, 'npub1')) {
                    $recipientPubkey = $key->convertToHex($recipientIdent);
                } else {
                    $recipientPubkey = $recipientIdent;
                }

                // Calculate share
                $sharePercent = ($weight / $totalWeight) * 100;
                $shareSats = (int) round(($weight / $totalWeight) * $totalAmount);

                if ($shareSats < 1) {
                    continue;
                }

                // Get lightning address from metadata cache
                $metadata = $this->redisCacheService->getMetadata($recipientPubkey);
                $lud16 = $metadata->lud16;
                $lud06 = $metadata->lud06;

                if (empty($lud16) && empty($lud06)) {
                    $invoices[] = [
                        'recipient' => $recipientIdent,
                        'amount' => $shareSats,
                        'sharePercent' => round($sharePercent, 1),
                        'error' => 'No Lightning address configured',
                        'bolt11' => null,
                        'qrSvg' => null,
                    ];
                    continue;
                }

                try {
                    // Create invoice for this recipient
                    $amountMillisats = $shareSats * 1000;

                    // Handle lud16/lud06 as arrays (take first element if array)
                    if (is_array($lud16)) {
                        $lud16 = !empty($lud16) ? $lud16[0] : null;
                    }
                    if (is_array($lud06)) {
                        $lud06 = !empty($lud06) ? $lud06[0] : null;
                    }

                    // Resolve LNURL
                    $lnurlInfo = $this->lnurlResolver->resolve($lud16, $lud06);

                    // Build zap request with default relay
                    $zapRequestJson = $this->nostrSigner->buildZapRequest(
                        recipientPubkey: $recipientPubkey,
                        amountMillisats: $amountMillisats,
                        lnurl: $lnurlInfo->bech32 ?? $lud16,
                        comment: $comment,
                        relays: [$this->defaultRelay], // Include default relay for NIP-57 compliance
                        zapSplits: []
                    );

                    // Request invoice
                    $bolt11 = $this->lnurlResolver->requestInvoice(
                        callback: $lnurlInfo->callback,
                        amountMillisats: $amountMillisats,
                        nostrEvent: $zapRequestJson,
                        lnurl: $lnurlInfo->bech32
                    );

                    // Generate QR code
                    $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($bolt11), 280);

                    $invoices[] = [
                        'recipient' => $recipientIdent,
                        'recipientName' => $metadata->displayName ?: $metadata->name ?: 'Author',
                        'amount' => $shareSats,
                        'sharePercent' => round($sharePercent, 1),
                        'bolt11' => $bolt11,
                        'qrSvg' => $qrSvg,
                        'error' => null,
                    ];

                } catch (\Exception $e) {
                    $this->logger->warning('Failed to create split invoice', [
                        'recipient' => $recipientIdent,
                        'error' => $e->getMessage(),
                    ]);

                    $invoices[] = [
                        'recipient' => $recipientIdent,
                        'amount' => $shareSats,
                        'sharePercent' => round($sharePercent, 1),
                        'error' => $e->getMessage(),
                        'bolt11' => null,
                        'qrSvg' => null,
                    ];
                }
            }

            if (empty($invoices)) {
                return new JsonResponse(['error' => 'Could not create any invoices'], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse([
                'success' => true,
                'invoices' => $invoices,
                'totalAmount' => $totalAmount,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Unexpected error creating split invoices', [
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Failed to create invoices: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

