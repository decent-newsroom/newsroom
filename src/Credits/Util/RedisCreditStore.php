<?php

namespace App\Credits\Util;

use App\Credits\Entity\CreditTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @deprecated Since v0.0.21 — Search credits system has been deprecated.
 */
readonly class RedisCreditStore
{
    public function __construct(
        private CacheInterface $creditsCache,
        private EntityManagerInterface $em
    ) {}

    private function key(string $npub): string
    {
        return 'credits_' . $npub;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function resetBalance(string $npub): int
    {
        $this->creditsCache->delete($this->key($npub));

        // Fetch all transactions for the given npub
        $transactions = $this->em->getRepository(CreditTransaction::class)
            ->findBy(['npub' => $npub]);

        // Initialize the balance
        $balance = 0;

        // Calculate the final balance based on the transactions
        foreach ($transactions as $tx) {
            if ($tx->getType() === 'credit') {
                $balance += $tx->getAmount();
            } elseif ($tx->getType() === 'debit') {
                $balance -= $tx->getAmount();
            }
        }

        // Write the calculated balance into the Redis cache
        $item = $this->creditsCache->getItem($this->key($npub));
        $item->set($balance);
        $this->creditsCache->save($item);

        return $balance;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getBalance(string $npub): int
    {
        // Use cache pool to fetch the credit balance
        return $this->creditsCache->get($this->key($npub), function () use ($npub) {
            return $this->resetBalance($npub);
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    public function addCredits(string $npub, int $amount): void
    {
        $currentBalance = $this->getBalance($npub);
        $item = $this->creditsCache->getItem($this->key($npub));
        $balance = $currentBalance + $amount;
        $item->set($balance);
        $this->creditsCache->save($item);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function spendCredits(string $npub, int $amount): void
    {
        $currentBalance = $this->getBalance($npub);
        $item = $this->creditsCache->getItem($this->key($npub));
        if ($currentBalance < $amount) {
            throw new \RuntimeException('Insufficient credits');
        }
        $balance = $currentBalance - $amount;
        $item->set($balance);
        $this->creditsCache->save($item);
    }
}
