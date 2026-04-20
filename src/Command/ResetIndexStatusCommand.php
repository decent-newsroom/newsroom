<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use App\Enum\KindsEnum;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recovery tool for articles that were wrongly flagged as DO_NOT_INDEX
 * (e.g. by the legacy slug-only deduplication pass) and therefore evicted
 * from Elasticsearch, which made them disappear from author profile tabs
 * and magazine categories even though they still exist in the database.
 *
 * Flips matching articles back to TO_BE_INDEXED so that `articles:index`
 * will re-persist them to Elastic. After running this, run:
 *   bin/console articles:index
 *   bin/console articles:indexed
 */
#[AsCommand(
    name: 'articles:reset-index-status',
    description: 'Reset DO_NOT_INDEX longform articles back to TO_BE_INDEXED so they can be reindexed.'
)]
class ResetIndexStatusCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pubkey', null, InputOption::VALUE_REQUIRED, 'Limit to a single author (hex pubkey or npub).')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Reset every DO_NOT_INDEX longform article across the database. Mutually exclusive with --pubkey.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report how many rows would be updated.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pubkeyOpt = $input->getOption('pubkey');
        $all = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$all && $pubkeyOpt === null) {
            $io->error('Specify --pubkey=<npub|hex> or --all.');
            return Command::INVALID;
        }

        if ($all && $pubkeyOpt !== null) {
            $io->error('--all and --pubkey are mutually exclusive.');
            return Command::INVALID;
        }

        $pubkeyHex = null;
        if ($pubkeyOpt !== null) {
            $pubkeyHex = $this->toHex($pubkeyOpt);
            if ($pubkeyHex === null) {
                $io->error('Invalid pubkey. Provide a 64-char hex string or an npub.');
                return Command::INVALID;
            }
        }

        $qb = $this->em->createQueryBuilder()
            ->from(Article::class, 'a')
            ->where('a.indexStatus = :status')
            ->setParameter('status', IndexStatusEnum::DO_NOT_INDEX)
            ->andWhere('a.kind = :kind')
            ->setParameter('kind', KindsEnum::LONGFORM);

        if ($pubkeyHex !== null) {
            $qb->andWhere('a.pubkey = :pk')->setParameter('pk', $pubkeyHex);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            $io->success('Nothing to reset. No DO_NOT_INDEX longform articles matched.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> article(s) flagged DO_NOT_INDEX%s.', $total, $pubkeyHex ? ' for pubkey ' . $pubkeyHex : ''));

        if ($dryRun) {
            $io->note('Dry run — no changes written. Re-run without --dry-run to apply.');
            return Command::SUCCESS;
        }

        $update = $this->em->createQueryBuilder()
            ->update(Article::class, 'a')
            ->set('a.indexStatus', ':new')
            ->setParameter('new', IndexStatusEnum::TO_BE_INDEXED)
            ->where('a.indexStatus = :old')
            ->setParameter('old', IndexStatusEnum::DO_NOT_INDEX)
            ->andWhere('a.kind = :kind')
            ->setParameter('kind', KindsEnum::LONGFORM);

        if ($pubkeyHex !== null) {
            $update->andWhere('a.pubkey = :pk')->setParameter('pk', $pubkeyHex);
        }

        $affected = $update->getQuery()->execute();

        $io->success(sprintf('Reset %d article(s) to TO_BE_INDEXED.', (int) $affected));
        $io->writeln('Next steps:');
        $io->writeln('  bin/console articles:index      # persist to Elasticsearch');
        $io->writeln('  bin/console articles:indexed    # mark as INDEXED');

        return Command::SUCCESS;
    }

    private function toHex(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^[0-9a-f]{64}$/i', $input) === 1) {
            return strtolower($input);
        }
        if (str_starts_with($input, 'npub1')) {
            try {
                return NostrKeyUtil::npubToHex($input);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}

