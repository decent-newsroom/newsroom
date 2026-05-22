<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use App\Entity\EssayistMembership;
use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\EssayistMembershipRepository;
use App\Repository\UserEntityRepository;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records zap-receipt-backed Essayist membership grants and keeps
 * `ROLE_ESSAYIST_MEMBER` in sync with the latest expiry.
 *
 * The ledger entity (one row per zap receipt id) is the source of truth;
 * the role is a denormalized projection used by Symfony security and the
 * gateway membership cache (Redis).
 *
 * Replay protection is enforced by the unique index on `zap_receipt_event_id`
 * — duplicate `recordGrant()` calls for the same receipt are a no-op.
 */
final class EssayistMembershipService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EssayistMembershipRepository $membershipRepository,
        private readonly UserEntityRepository $userRepository,
        private readonly EssayistMembershipCacheService $membershipCache,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'essayist.membership.minimum_sats')]
        private readonly int $minimumSats = 1000,
    ) {
    }

    public function getMinimumSats(): int { return $this->minimumSats; }

    /**
     * Compute the calendar-based expiry for a payment made at `$paidAt`.
     * Memberships run on monthly ticks: any payment during month M activates
     * (or confirms) access through the last second of month M+1. Multiple
     * payments in the same month do not stack — they all converge on the
     * same end-of-next-month target. A payment in a later month extends
     * the window further forward.
     */
    public static function endOfNextMonth(\DateTimeImmutable $paidAt): \DateTimeImmutable
    {
        // Normalize to UTC so the boundary is consistent regardless of server tz.
        $paidUtc = $paidAt->setTimezone(new \DateTimeZone('UTC'));
        // First day of the month after paidAt, then last moment of *that* month.
        $firstOfNext   = $paidUtc->modify('first day of next month')->setTime(0, 0, 0);
        $lastOfNext    = $firstOfNext->modify('last day of this month')->setTime(23, 59, 59);
        return $lastOfNext;
    }

    /**
     * Persist a new ledger row for the given zap receipt and grant /extend
     * `ROLE_ESSAYIST_MEMBER` on the payer's User entity.
     *
     * @param string $payerPubkeyHex         32-byte hex pubkey of the contributor (must be valid)
     * @param string $contributedToPubkeyHex 32-byte hex pubkey of the existing member sponsor
     * @param string $zapReceiptEventId      Kind 9735 event id (idempotency key)
     * @param int    $amountSats             Payment amount in sats
     * @param \DateTimeImmutable|null $paidAt The receipt's `created_at` (defaults to now)
     *
     * @return EssayistMembership|null Returns the persisted row, or null if the
     *                                  payment did not meet the minimum / was a
     *                                  duplicate / payer pubkey was invalid.
     */
    public function recordGrant(
        string $payerPubkeyHex,
        string $contributedToPubkeyHex,
        string $zapReceiptEventId,
        int $amountSats,
        ?\DateTimeImmutable $paidAt = null,
    ): ?EssayistMembership {
        if ($amountSats < $this->minimumSats) {
            $this->logger->info('Essayist zap below minimum, skipping grant', [
                'receipt'   => $zapReceiptEventId,
                'amount'    => $amountSats,
                'minimum'   => $this->minimumSats,
            ]);
            return null;
        }

        if (!NostrKeyUtil::isHexPubkey($payerPubkeyHex) || !NostrKeyUtil::isHexPubkey($contributedToPubkeyHex)) {
            return null;
        }

        // Idempotency: a single receipt grants at most once.
        if ($this->membershipRepository->findByZapReceiptId($zapReceiptEventId) !== null) {
            return null;
        }

        $paidAt ??= new \DateTimeImmutable('now');

        // Find or create the payer's User row.
        $payerNpub = NostrKeyUtil::hexToNpub($payerPubkeyHex);
        $user      = $this->userRepository->findOneBy(['npub' => $payerNpub]);
        if ($user === null) {
            $user = new User();
            $user->setNpub($payerNpub);
            $user->setRoles([]);
            $this->em->persist($user);
        }

        // Calendar-month rule: any zap during month M extends access through
        // the end of month M+1. Multiple zaps in the same month converge on
        // the same target (no stacking); a zap in a later month bumps the
        // window forward. The effective expiry is therefore the max of the
        // existing expiry and the "end of next month from paidAt".
        $targetExpiresAt = self::endOfNextMonth($paidAt);
        $latest          = $this->membershipRepository->findLatestForUser($user);
        if ($latest !== null && $latest->getExpiresAt() > $targetExpiresAt) {
            $newExpiresAt = $latest->getExpiresAt();
        } else {
            $newExpiresAt = $targetExpiresAt;
        }

        $grant = new EssayistMembership();
        $grant->setUser($user);
        $grant->setPayerPubkey($payerPubkeyHex);
        $grant->setContributedToPubkey($contributedToPubkeyHex);
        $grant->setZapReceiptEventId($zapReceiptEventId);
        $grant->setAmountSats($amountSats);
        $grant->setStartedAt($paidAt);
        $grant->setExpiresAt($newExpiresAt);

        $this->em->persist($grant);

        if (!in_array(RolesEnum::ESSAYIST_MEMBER->value, $user->getRoles(), true)) {
            $user->addRole(RolesEnum::ESSAYIST_MEMBER->value);
        }

        $this->em->flush();

        // Pre-warm the gateway membership cache.
        $this->membershipCache->markApproved($payerNpub);

        $this->logger->info('Essayist membership granted via zap receipt', [
            'receipt' => $zapReceiptEventId,
            'payer'   => substr($payerPubkeyHex, 0, 8),
            'sponsor' => substr($contributedToPubkeyHex, 0, 8),
            'sats'    => $amountSats,
            'until'   => $newExpiresAt->format(DATE_ATOM),
        ]);

        return $grant;
    }

    /**
     * Revoke `ROLE_ESSAYIST_MEMBER` from users whose latest membership row
     * has expired. Intended for cron use.
     *
     * @return int Number of users from whom the role was revoked.
     */
    public function expireLapsed(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now');

        // For each user with at least one membership row, check the latest.
        $sql = <<<'SQL'
            SELECT user_id, MAX(expires_at) AS max_exp
            FROM essayist_membership
            GROUP BY user_id
            HAVING MAX(expires_at) < :now
        SQL;
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative($sql, ['now' => $now->format('Y-m-d H:i:s')]);

        $revoked = 0;
        foreach ($rows as $row) {
            /** @var User|null $user */
            $user = $this->userRepository->find((int) $row['user_id']);
            if (!$user instanceof User) {
                continue;
            }
            // Skip early-bird users (free membership for June) and admins.
            if (in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $user->getRoles(), true)) {
                continue;
            }
            if (in_array(RolesEnum::ESSAYIST_MEMBER->value, $user->getRoles(), true)) {
                $user->removeRole(RolesEnum::ESSAYIST_MEMBER->value);
                $this->em->persist($user);
                $this->membershipCache->markRevoked((string) $user->getNpub());
                $revoked++;
            }
        }

        if ($revoked > 0) {
            $this->em->flush();
        }

        return $revoked;
    }
}



