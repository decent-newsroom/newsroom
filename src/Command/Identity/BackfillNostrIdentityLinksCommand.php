<?php

declare(strict_types=1);

namespace App\Command\Identity;

use App\Entity\User;
use App\Service\Nostr\NostrIdentityService;
use DecentNewsroom\IdentityBundle\Entity\UserIdentityLink;
use DecentNewsroom\IdentityBundle\Repository\UserIdentityLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'identity:backfill-nostr-links',
    description: 'Create IdentityBundle nostr identity links for existing npub-backed users.',
)]
final class BackfillNostrIdentityLinksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserIdentityLinkRepository $links,
        private readonly NostrIdentityService $nostrIdentity,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be linked without writing rows')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of users to scan per batch', 100)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum users to scan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $limitOption = $input->getOption('limit');
        $remaining = $limitOption === null ? null : max(0, (int) $limitOption);

        $io->title('Backfill Nostr Identity Links');
        if ($dryRun) {
            $io->note('Dry-run mode: no identity_user_link rows will be written.');
        }

        $scanned = 0;
        $created = 0;
        $existing = 0;
        $invalid = 0;
        $conflicts = 0;
        $lastId = 0;

        while ($remaining === null || $remaining > 0) {
            $pageSize = $remaining === null ? $batchSize : min($batchSize, $remaining);
            if ($pageSize <= 0) {
                break;
            }

            $users = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->andWhere('u.id > :lastId')
                ->andWhere('u.npub IS NOT NULL')
                ->setParameter('lastId', $lastId)
                ->orderBy('u.id', 'ASC')
                ->setMaxResults($pageSize)
                ->getQuery()
                ->getResult();

            if ($users === []) {
                break;
            }

            foreach ($users as $user) {
                \assert($user instanceof User);
                $scanned++;
                $lastId = (int) $user->getId();

                if ($remaining !== null) {
                    $remaining--;
                }

                $npub = $user->getNpub();
                if ($npub === null || $npub === '') {
                    continue;
                }

                try {
                    $externalId = $this->nostrIdentity->toHex($npub);
                } catch (\Throwable $e) {
                    $invalid++;
                    $io->warning(sprintf('Skipping user %d with invalid npub: %s', $user->getId(), $npub));
                    continue;
                }

                $existingLink = $this->links->findOneByProviderAndExternalId('nostr', $externalId);
                if ($existingLink !== null) {
                    if ($existingLink->getOwnerId() !== $user->getIdentityOwnerId()) {
                        $conflicts++;
                        $io->warning(sprintf(
                            'Conflict: nostr identity %s is linked to owner %s, not user %s.',
                            $externalId,
                            $existingLink->getOwnerId(),
                            $user->getIdentityOwnerId(),
                        ));
                    } else {
                        $existing++;
                    }
                    continue;
                }

                $created++;
                if (!$dryRun) {
                    $link = new UserIdentityLink($user->getIdentityOwnerId(), 'nostr', $externalId);
                    $link->markVerified();
                    $this->entityManager->persist($link);
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Metric', 'Count'], [
            ['Scanned users', $scanned],
            ['Created links', $created],
            ['Already linked', $existing],
            ['Invalid npubs', $invalid],
            ['Conflicts', $conflicts],
        ]);

        if ($conflicts > 0 || $invalid > 0) {
            $io->warning('Backfill completed with skipped rows; review the warnings above.');
        } else {
            $io->success('Backfill completed.');
        }

        return Command::SUCCESS;
    }
}
