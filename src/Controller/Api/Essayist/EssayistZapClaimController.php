<?php

declare(strict_types=1);

namespace App\Controller\Api\Essayist;

use App\Entity\EssayistZapClaim;
use App\Service\Essayist\EssayistZapClaimService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoints for Essayist membership zap claim/verification.
 */
#[Route('/api/essayist', name: 'api_essayist_')]
class EssayistZapClaimController extends AbstractController
{
    public function __construct(
        private readonly EssayistZapClaimService $claimService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Submit a new zap claim.
     *
     * POST /api/essayist/claim-zap
     *
     * Expects JSON:
     * {
     *   "zapReceiptEventId": "...",  // optional, kind 9735 event ID
     *   "bolt11Invoice": "...",      // optional, BOLT11 invoice
     *   "sponsorNpub": "...",        // required, the sponsor's npub
     *   "claimedAmountSats": 5000    // optional, claimed amount
     * }
     */
    #[Route('/claim-zap', name: 'claim_zap', methods: ['POST'])]
    public function claimZap(Request $request): JsonResponse
    {
        // Require authentication
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $zapReceiptEventId = $data['zapReceiptEventId'] ?? null;
            $bolt11Invoice     = $data['bolt11Invoice'] ?? null;
            $paymentPreimage   = $data['paymentPreimage'] ?? null;
            $sponsorNpub       = $data['sponsorNpub'] ?? null;
            $claimedAmountSats = isset($data['claimedAmountSats']) ? (int) $data['claimedAmountSats'] : null;

            // Validate: at least one proof is required
            if (!$zapReceiptEventId && !$bolt11Invoice && !$paymentPreimage) {
                return $this->json(
                    ['error' => 'At least one of zapReceiptEventId, bolt11Invoice, or paymentPreimage is required'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Preimage requires invoice for verification
            if ($paymentPreimage && !$bolt11Invoice) {
                return $this->json(
                    ['error' => 'paymentPreimage requires bolt11Invoice to verify against'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Validate sponsor pubkey
            if (!$sponsorNpub) {
                return $this->json(['error' => 'sponsorNpub is required'], Response::HTTP_BAD_REQUEST);
            }

            // Convert sponsor npub to hex
            try {
                $sponsorHex = NostrKeyUtil::npubToHex($sponsorNpub);
            } catch (\Throwable $e) {
                return $this->json(
                    ['error' => 'Invalid sponsor npub: ' . $e->getMessage()],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Get current user's pubkey
            $payerNpub = $user->getNpub();
            if (!$payerNpub) {
                return $this->json(
                    ['error' => 'User does not have a linked Nostr pubkey'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $payerHex = NostrKeyUtil::npubToHex($payerNpub);

            // Check for duplicates
            if ($zapReceiptEventId) {
                $existing = $this->em->getRepository(EssayistZapClaim::class)->findByZapReceiptEventId($zapReceiptEventId);
                if ($existing !== null) {
                    return $this->json(
                        ['error' => 'This zap receipt has already been claimed'],
                        Response::HTTP_CONFLICT
                    );
                }
            }

            // Create the claim
            $claim = $this->claimService->createClaim(
                user: $user,
                payerPubkeyHex: $payerHex,
                sponsorPubkeyHex: $sponsorHex,
                zapReceiptEventId: $zapReceiptEventId,
                bolt11Invoice: $bolt11Invoice,
                paymentPreimage: $paymentPreimage,
                claimedAmountSats: $claimedAmountSats,
            );

            // Attempt auto-verification
            $verified = $this->claimService->verifyClaim($claim);

            return $this->json([
                'id' => $claim->getId(),
                'status' => $claim->getStatus(),
                'verified' => $verified,
                'message' => $verified
                    ? 'Zap claim verified and membership extended!'
                    : 'Zap claim submitted and pending verification. Recipient attestation or admin review is required.',
            ], $verified ? Response::HTTP_CREATED : Response::HTTP_ACCEPTED);
        } catch (\Throwable $e) {
            $this->logger->error('Error processing zap claim', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json(
                ['error' => 'An error occurred while processing your claim'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get pending claims for the current user.
     *
     * GET /api/essayist/my-claims
     */
    #[Route('/my-claims', name: 'my_claims', methods: ['GET'])]
    public function getMyClaims(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var \App\Repository\EssayistZapClaimRepository $claimRepo */
        $claimRepo = $this->em->getRepository(EssayistZapClaim::class);
        $claims = $claimRepo->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->json(array_map(function (EssayistZapClaim $claim) {
            return [
                'id' => $claim->getId(),
                'status' => $claim->getStatus(),
                'createdAt' => $claim->getCreatedAt()->format('c'),
                'verifiedAt' => $claim->getVerifiedAt()?->format('c'),
                'claimedAmountSats' => $claim->getClaimedAmountSats(),
                'verificationMethod' => $claim->getVerificationMethod(),
                'rejectionReason' => $claim->getRejectionReason(),
            ];
        }, $claims));
    }
}







