<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use App\Entity\EssayistZapClaim;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\EssayistZapClaimRepository;
use App\Repository\EventRepository;
use App\Util\Bolt11PaymentVerifier;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;

/**
 * Verification paths for Essayist membership claims:
 * 1) payer proof (invoice + preimage),
 * 2) zap receipt (kind 9735),
 * 3) recipient attestation (superchat-like fallback),
 * 4) admin manual fallback.
 */
final class EssayistZapClaimService
{
    public function __construct(
        private readonly EssayistZapClaimRepository $claimRepository,
        private readonly EventRepository $eventRepository,
        private readonly EssayistMembershipService $membershipService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createClaim(
        User $user,
        string $payerPubkeyHex,
        string $sponsorPubkeyHex,
        ?string $zapReceiptEventId = null,
        ?string $bolt11Invoice = null,
        ?string $paymentPreimage = null,
        ?int $claimedAmountSats = null,
    ): EssayistZapClaim {
        $claim = new EssayistZapClaim();
        $claim->setUser($user);
        $claim->setPayerPubkey($payerPubkeyHex);
        $claim->setSponsorPubkey($sponsorPubkeyHex);
        $claim->setZapReceiptEventId($zapReceiptEventId);
        $claim->setBolt11Invoice($bolt11Invoice);
        $claim->setPaymentPreimage($paymentPreimage);
        $claim->setClaimedAmountSats($claimedAmountSats);
        $claim->setStatus('pending');

        $this->claimRepository->save($claim);

        return $claim;
    }

    public function verifyClaim(EssayistZapClaim $claim): bool
    {
        if (!$claim->isPending()) {
            return false;
        }

        if ($claim->getPaymentPreimage() && $claim->getBolt11Invoice()) {
            return $this->verifyByPreimage($claim);
        }

        if ($claim->getZapReceiptEventId()) {
            $receipt = $this->eventRepository->findOneBy([
                'id' => $claim->getZapReceiptEventId(),
                'kind' => 9735,
            ]);
            if ($receipt instanceof Event) {
                return $this->verifyByReceipt($claim, $receipt);
            }
        }

        // Invoice-only claims stay pending until recipient attestation or admin action.
        return false;
    }

    public function attestByRecipient(
        EssayistZapClaim $claim,
        User $recipient,
        ?int $amountSats = null,
        ?string $attestationEventId = null,
        ?string $note = null,
    ): bool {
        if (!$claim->isPending()) {
            return false;
        }

        $recipientNpub = (string) ($recipient->getNpub() ?? '');
        if (!NostrKeyUtil::isNpub($recipientNpub)) {
            return false;
        }

        $recipientHex = NostrKeyUtil::npubToHex($recipientNpub);
        if (!hash_equals($claim->getSponsorPubkey(), $recipientHex)) {
            return false;
        }

        $resolvedAmount = $amountSats;
        if ($resolvedAmount === null || $resolvedAmount < 1) {
            $resolvedAmount = $claim->getClaimedAmountSats();
        }
        if (($resolvedAmount === null || $resolvedAmount < 1) && $claim->getBolt11Invoice()) {
            $resolvedAmount = Bolt11PaymentVerifier::extractAmountSats($claim->getBolt11Invoice());
        }
        if ($resolvedAmount === null || $resolvedAmount < 1) {
            return false;
        }

        $receiptId = $claim->getZapReceiptEventId();
        if (!$receiptId) {
            // Deterministic per claim; prevents duplicate grants on repeated attest action.
            $receiptId = hash('sha256', 'recipient-attest:' . (string) $claim->getId());
        }

        $grant = $this->membershipService->recordGrant(
            payerPubkeyHex: $claim->getPayerPubkey(),
            contributedToPubkeyHex: $claim->getSponsorPubkey(),
            zapReceiptEventId: $receiptId,
            amountSats: $resolvedAmount,
            paidAt: new \DateTimeImmutable(),
        );

        if ($grant === null) {
            return false;
        }

        $claim->setStatus('verified');
        $claim->setVerificationMethod('recipient_attestation');
        $claim->setVerifiedAt(new \DateTimeImmutable());
        $claim->setClaimedAmountSats($resolvedAmount);
        $claim->setRecipientAttestorPubkey($recipientHex);
        $claim->setRecipientAttestationEventId($attestationEventId ?: null);
        $claim->setRecipientAttestationNote($note ?: null);
        $this->claimRepository->save($claim);

        $this->logger->info('Essayist claim verified by recipient attestation', [
            'claim_id' => $claim->getId(),
            'recipient' => substr($recipientHex, 0, 8),
            'amount' => $resolvedAmount,
        ]);

        return true;
    }

    public function approveClaim(EssayistZapClaim $claim, int $amountSats): bool
    {
        if (!$claim->isPending()) {
            return false;
        }

        $pseudoReceiptId = hash('sha256', 'admin-approve:' . (string) $claim->getId());

        $grant = $this->membershipService->recordGrant(
            payerPubkeyHex: $claim->getPayerPubkey(),
            contributedToPubkeyHex: $claim->getSponsorPubkey(),
            zapReceiptEventId: $pseudoReceiptId,
            amountSats: $amountSats,
            paidAt: new \DateTimeImmutable(),
        );

        if ($grant === null) {
            return false;
        }

        $claim->setStatus('verified');
        $claim->setVerificationMethod('manual');
        $claim->setVerifiedAt(new \DateTimeImmutable());
        $claim->setClaimedAmountSats($amountSats);
        $this->claimRepository->save($claim);

        return true;
    }

    public function rejectClaim(EssayistZapClaim $claim, string $reason): void
    {
        $claim->setStatus('rejected');
        $claim->setRejectionReason($reason);
        $this->claimRepository->save($claim);
    }

    private function verifyByPreimage(EssayistZapClaim $claim): bool
    {
        try {
            $valid = Bolt11PaymentVerifier::verifyPreimage(
                (string) $claim->getPaymentPreimage(),
                (string) $claim->getBolt11Invoice(),
            );
        } catch (\InvalidArgumentException $e) {
            $claim->setStatus('rejected');
            $claim->setRejectionReason('Invalid preimage/invoice proof: ' . $e->getMessage());
            $this->claimRepository->save($claim);
            return false;
        }

        if (!$valid) {
            $claim->setStatus('rejected');
            $claim->setRejectionReason('Preimage does not match invoice payment hash.');
            $this->claimRepository->save($claim);
            return false;
        }

        $amountSats = Bolt11PaymentVerifier::extractAmountSats((string) $claim->getBolt11Invoice())
            ?? $claim->getClaimedAmountSats();

        if ($amountSats === null || $amountSats < 1) {
            $claim->setStatus('rejected');
            $claim->setRejectionReason('Could not resolve payment amount from invoice proof.');
            $this->claimRepository->save($claim);
            return false;
        }

        $receiptId = hash('sha256', 'preimage:' . (string) $claim->getPaymentPreimage());

        $grant = $this->membershipService->recordGrant(
            payerPubkeyHex: $claim->getPayerPubkey(),
            contributedToPubkeyHex: $claim->getSponsorPubkey(),
            zapReceiptEventId: $receiptId,
            amountSats: $amountSats,
            paidAt: new \DateTimeImmutable(),
        );

        if ($grant === null) {
            return false;
        }

        $claim->setStatus('verified');
        $claim->setVerificationMethod('preimage');
        $claim->setVerifiedAt(new \DateTimeImmutable());
        $claim->setClaimedAmountSats($amountSats);
        $this->claimRepository->save($claim);

        return true;
    }

    private function verifyByReceipt(EssayistZapClaim $claim, Event $receipt): bool
    {
        $amountSats = $this->extractAmountFromReceipt($receipt);
        if ($amountSats === null || $amountSats < 1) {
            $claim->setStatus('rejected');
            $claim->setRejectionReason('Could not parse amount from zap receipt.');
            $this->claimRepository->save($claim);
            return false;
        }

        $grant = $this->membershipService->recordGrant(
            payerPubkeyHex: $claim->getPayerPubkey(),
            contributedToPubkeyHex: $claim->getSponsorPubkey(),
            zapReceiptEventId: (string) $claim->getZapReceiptEventId(),
            amountSats: $amountSats,
            paidAt: new \DateTimeImmutable('@' . $receipt->getCreatedAt()),
        );

        if ($grant === null) {
            return false;
        }

        $claim->setStatus('verified');
        $claim->setVerificationMethod('auto_receipt');
        $claim->setVerifiedAt(new \DateTimeImmutable());
        $claim->setClaimedAmountSats($amountSats);
        $this->claimRepository->save($claim);

        return true;
    }

    private function extractAmountFromReceipt(Event $receipt): ?int
    {
        foreach ($receipt->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            if (($tag[0] ?? null) === 'amount') {
                return (int) ceil(((int) $tag[1]) / 1000);
            }
        }

        return null;
    }
}
