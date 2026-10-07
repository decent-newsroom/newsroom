<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Service\MutedPubkeysService;
use Doctrine\ORM\EntityManagerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Psr\Log\LoggerInterface;

class AuthorBanService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserEntityRepository $users,
        private readonly MutedPubkeysService $mutedPubkeys,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param list<string> $pubkeys */
    public function ban(array $pubkeys, string $reason, string $addedBy): void
    {
        foreach (array_chunk($pubkeys, 100) as $batch) {
            $this->em->getConnection()->transactional(function () use ($batch, $reason, $addedBy): void {
                foreach ($batch as $hex) {
                    $npub = PublicKey::fromHex($hex)?->toBech32()
                        ?? throw new \InvalidArgumentException('Invalid pubkey in author ban list.');
                    $this->em->getConnection()->executeStatement(
                        'INSERT INTO banned_pubkey (pubkey, reason, added_at, added_by) VALUES (:pubkey, :reason, :addedAt, :addedBy) ON CONFLICT (pubkey) DO NOTHING',
                        ['pubkey' => $hex, 'reason' => $reason, 'addedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'addedBy' => $addedBy],
                    );
                    $user = $this->users->findOneBy(['npub' => $npub]);
                    if ($user === null) {
                        $user = new User();
                        $user->setNpub($npub);
                        $this->em->persist($user);
                    }
                    $user->addRole(RolesEnum::MUTED->value);
                }
                $this->em->flush();
            });
            $this->mutedPubkeys->invalidateCache();
            $this->em->clear();
            $this->logger->warning('Committed permanent author ingestion bans', [
                'pubkeys' => $batch,
                'reason' => $reason,
                'added_by' => $addedBy,
            ]);
        }
    }
}
