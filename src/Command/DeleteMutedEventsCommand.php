<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserEntityRepository;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bulk delete all events, articles, highlights and magazines authored by
 * every muted user (ROLE_MUTED) with cascade cleanup.
 *
 * This is the "reaper" companion to the admin mute action: muting an author
 * hides new content at query time, this command purges already-stored content.
 *
 * Usage:
 *   docker compose exec php bin/console admin:delete-muted-events --dry-run
 *   docker compose exec php bin/console admin:delete-muted-events
 *   docker compose exec php bin/console admin:delete-muted-events --confirm
 */
#[AsCommand(
    name: 'admin:delete-muted-events',
    description: 'Bulk delete all events and articles authored by muted users (ROLE_MUTED) with cascade cleanup',
)]
class DeleteMutedEventsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserEntityRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview counts without deleting')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Skip confirmation prompt (dangerous!)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $confirm = $input->getOption('confirm');

        $pubkeys = $this->resolveMutedPubkeys($io);

        if ($pubkeys === []) {
            $io->info('No muted users with a resolvable pubkey found. Nothing to do.');
            return Command::SUCCESS;
        }

        $conn = $this->em->getConnection();
        $counts = $this->getCounts($conn, $pubkeys);

        if ($counts['total'] === 0 && $counts['articles'] === 0
            && $counts['highlights'] === 0 && $counts['magazines'] === 0) {
            $io->info(sprintf('No stored content found for %d muted user(s).', count($pubkeys)));
            return Command::SUCCESS;
        }

        $io->section('Deletion Summary');
        $io->listing([
            sprintf('Muted users: %d', count($pubkeys)),
            sprintf('Events: %d', $counts['total']),
            sprintf('  • Articles (30023/30024): %d', $counts['articles']),
            sprintf('  • Highlights (9802): %d', $counts['highlights']),
            sprintf('  • Magazines (30040/30041): %d', $counts['magazines']),
            sprintf('  • Other events: %d', $counts['other']),
        ]);

        if ($dryRun) {
            $io->success('[DRY RUN] No changes made.');
            return Command::SUCCESS;
        }

        if (! $confirm) {
            $io->warning(sprintf(
                'This will PERMANENTLY DELETE all stored content from %d muted user(s).',
                count($pubkeys),
            ));
            if (! $io->confirm('Are you absolutely sure?', false)) {
                $io->info('Aborted.');
                return Command::SUCCESS;
            }
        }

        $io->section('Deleting...');

        try {
            $deletedArticles = $this->deleteFromTable($conn, 'article', $pubkeys);
            $deletedHighlights = $this->deleteFromTable($conn, 'highlight', $pubkeys);
            $deletedMagazines = $this->deleteFromTable($conn, 'magazine', $pubkeys);
            $deletedEvents = $this->deleteFromTable($conn, 'event', $pubkeys);

            $this->logger->warning('admin:delete-muted-events executed', [
                'muted_users' => count($pubkeys),
                'deleted_events' => $deletedEvents,
                'deleted_articles' => $deletedArticles,
                'deleted_highlights' => $deletedHighlights,
                'deleted_magazines' => $deletedMagazines,
            ]);

            $io->success(sprintf(
                'Deleted %d event(s) with cascade cleanup (articles: %d, highlights: %d, magazines: %d) across %d muted user(s)',
                $deletedEvents,
                $deletedArticles,
                $deletedHighlights,
                $deletedMagazines,
                count($pubkeys),
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Deletion failed: %s', $e->getMessage()));
            $this->logger->error('admin:delete-muted-events failed', [
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Resolve hex pubkeys for every muted user, skipping unparseable npubs.
     *
     * @return string[]
     */
    private function resolveMutedPubkeys(SymfonyStyle $io): array
    {
        $pubkeys = [];
        foreach ($this->userRepository->findMutedUsers() as $user) {
            $npub = $user->getNpub();
            if ($npub === null || ! str_starts_with(strtolower(trim((string) ($npub))), 'npub1')) {
                $io->warning(sprintf('Skipping muted user #%s with invalid npub: %s', $user->getId(), $npub ?? '(null)'));
                continue;
            }

            try {
                $pubkeys[] = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($npub));
            } catch (\Throwable) {
                $io->warning(sprintf('Skipping muted user #%s: could not decode npub %s', $user->getId(), $npub));
            }
        }

        return array_values(array_unique($pubkeys));
    }

    /**
     * Get counts of events and related data for a set of pubkeys.
     *
     * @param string[] $pubkeys
     * @return array{total: int, articles: int, highlights: int, magazines: int, other: int}
     */
    private function getCounts(Connection $conn, array $pubkeys): array
    {
        [$placeholders, $params] = $this->inClause($pubkeys);

        $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM event WHERE pubkey IN ({$placeholders})", $params);
        $articles = (int) $conn->fetchOne("SELECT COUNT(*) FROM article WHERE pubkey IN ({$placeholders})", $params);
        $highlights = (int) $conn->fetchOne("SELECT COUNT(*) FROM highlight WHERE pubkey IN ({$placeholders})", $params);
        $magazines = (int) $conn->fetchOne("SELECT COUNT(*) FROM magazine WHERE pubkey IN ({$placeholders})", $params);

        return [
            'total' => $total,
            'articles' => $articles,
            'highlights' => $highlights,
            'magazines' => $magazines,
            'other' => max(0, $total - $articles - $highlights - $magazines),
        ];
    }

    /**
     * Delete rows from a table for a set of pubkeys.
     *
     * @param string[] $pubkeys
     */
    private function deleteFromTable(Connection $conn, string $table, array $pubkeys): int
    {
        [$placeholders, $params] = $this->inClause($pubkeys);

        return $conn->executeStatement("DELETE FROM {$table} WHERE pubkey IN ({$placeholders})", $params);
    }

    /**
     * Build a positional IN() clause and matching params.
     *
     * @param string[] $pubkeys
     * @return array{0: string, 1: string[]}
     */
    private function inClause(array $pubkeys): array
    {
        $placeholders = implode(',', array_fill(0, count($pubkeys), '?'));

        return [$placeholders, array_values($pubkeys)];
    }
}
