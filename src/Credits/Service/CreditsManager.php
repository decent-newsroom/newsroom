<?php

namespace App\Credits\Service;

use App\Credits\Entity\CreditTransaction;
use App\Credits\Util\RedisCreditStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * @deprecated Since v0.0.21 — Search credits system has been deprecated.
 */
readonly class CreditsManager
{
    public function __construct(
        private RedisCreditStore       $redisStore,
        private EntityManagerInterface $em
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function getBalance(string $npub): int
    {
        return $this->redisStore->getBalance($npub);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function resetBalance(string $npub): int
    {
        return $this->redisStore->resetBalance($npub);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function addCredits(string $npub, int $amount, ?string $reason = null): void
    {
        $this->redisStore->addCredits($npub, $amount);

        $tx = new CreditTransaction($npub, $amount, 'credit', $reason);
        $this->em->persist($tx);
        $this->em->flush();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function canAfford(string $npub, int $cost): bool
    {
        return $this->getBalance($npub) >= $cost;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function spendCredits(string $npub, int $cost, ?string $reason = null): void
    {
        if (!$this->canAfford($npub, $cost)) {
            throw new \RuntimeException("Insufficient credits for $npub");
        }

        $this->redisStore->spendCredits($npub, $cost);

        $tx = new CreditTransaction($npub, $cost, 'debit', $reason);
        $this->em->persist($tx);
        $this->em->flush();
    }
}
