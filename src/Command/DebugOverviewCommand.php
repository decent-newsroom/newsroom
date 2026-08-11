<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Magazine;
use App\Enum\KindsEnum;
use App\Service\Cache\RedisViewStore;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnose why the author profile Overview tab is missing magazines / reading lists.
 *
 * Prints, for a given pubkey: the raw event inventory (kinds + 30040 subtypes),
 * what each Overview sub-query returns, and the currently cached Overview payload.
 * Read-only; makes no changes.
 */
#[AsCommand(
    name: 'profile:debug-overview',
    description: 'Diagnose missing magazines / reading lists on an author Overview tab (read-only).',
)]
class DebugOverviewCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedisViewStore $viewStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('npub', InputArgument::REQUIRED, 'npub or hex pubkey of the profile to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $npubInput = (string) $input->getArgument('npub');

        try {
            $pubkey = PublicKey::fromHex(strtolower(trim((string) ($npubInput)))) !== null
                ? $npubInput
                : (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($npubInput));
        } catch (\Throwable $e) {
            $io->error('Could not resolve to a hex pubkey: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->title('Overview diagnostics for ' . substr($pubkey, 0, 16) . '…');
        $io->writeln('Full hex: ' . $pubkey);

        $conn = $this->em->getConnection();
        $indexKind = KindsEnum::PUBLICATION_INDEX->value;

        // 1. Event inventory by kind
        $io->section('1. Event inventory (local Postgres) by kind');
        $kindRows = $conn->executeQuery(
            'SELECT kind, COUNT(*) AS n FROM event WHERE pubkey = :pk GROUP BY kind ORDER BY kind',
            ['pk' => $pubkey],
        )->fetchAllAssociative();
        if ($kindRows === []) {
            $io->warning('No events at all stored locally for this pubkey. Nothing to project — the events were never ingested into the local relay/DB.');
        } else {
            $io->table(['kind', 'count'], array_map(static fn ($r) => [$r['kind'], $r['n']], $kindRows));
        }

        // 2. Breakdown of kind-30040 publication indexes by `type` tag
        $io->section(sprintf('2. Publication-index (kind %d) events by "type" tag', $indexKind));
        $indexRows = $conn->executeQuery(
            'SELECT tags FROM event WHERE pubkey = :pk AND kind = :kind',
            ['pk' => $pubkey, 'kind' => $indexKind],
        )->fetchAllAssociative();

        if ($indexRows === []) {
            $io->warning(sprintf('No kind-%d events stored locally. Magazines AND reading lists both come from this kind, so both sections will be empty.', $indexKind));
        } else {
            $byType = [];
            $rowsForTable = [];
            foreach ($indexRows as $row) {
                $tags = $this->decodeTags($row['tags']);
                $type = $this->tagValue($tags, 'type') ?? '(none)';
                $dTag = $this->tagValue($tags, 'd') ?? '(none)';
                $hasNested = $this->hasNestedIndex($tags, $indexKind);
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                $rowsForTable[] = [$dTag, $type, $hasNested ? 'yes' : 'no'];
            }
            $io->writeln('Counts by type: ' . json_encode($byType));
            $io->table(['d (slug)', 'type', 'has nested 30040?'], $rowsForTable);
        }

        // 3. What each Overview sub-query returns
        $io->section('3. Overview sub-query results');

        $magazineEntities = $this->em->getRepository(Magazine::class)->count(['pubkey' => $pubkey]);
        $io->writeln(sprintf('Magazine entities (projected) with pubkey: <info>%d</info>', $magazineEntities));

        $ownReadingLists = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM event WHERE kind = :kind AND pubkey = :pk AND tags @> :needle::jsonb",
            ['kind' => $indexKind, 'pk' => $pubkey, 'needle' => '[["type","reading-list"]]'],
        )->fetchOne();
        $io->writeln(sprintf('Own reading lists (tags @> [["type","reading-list"]]): <info>%d</info>', $ownReadingLists));

        $featuredReadingLists = (int) $conn->executeQuery(
            "SELECT COUNT(*) FROM event e
             WHERE e.kind = :kind AND e.pubkey != :pk
               AND e.tags @> :needle::jsonb
               AND EXISTS (
                   SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                   WHERE tag->>0 = 'a' AND (tag->>1 LIKE :c23 OR tag->>1 LIKE :c24)
               )",
            [
                'kind' => $indexKind,
                'pk' => $pubkey,
                'needle' => '[["type","reading-list"]]',
                'c23' => '30023:' . $pubkey . ':%',
                'c24' => '30024:' . $pubkey . ':%',
            ],
        )->fetchOne();
        $io->writeln(sprintf('Featured-in reading lists (others referencing your articles): <info>%d</info>', $featuredReadingLists));

        // 4. Cached Overview payload
        $io->section('4. Cached Overview payload (Redis)');
        $cache = $this->viewStore->fetchProfileTabData($pubkey, 'overview');
        $io->writeln('isCached: ' . ($cache['isCached'] ? 'yes' : 'no') . ' | isStale: ' . ($cache['isStale'] ? 'yes' : 'no'));
        if (($cache['data'] ?? null) === null) {
            $io->warning('No cached overview payload. It will be rebuilt on next visit / by the async_profiles worker.');
        } else {
            $keys = ['authorMagazines', 'featuredMagazines', 'authorReadingLists', 'featuredReadingLists', 'existingFollowPacks', 'featuredInFollowPacks'];
            $counts = [];
            foreach ($keys as $k) {
                $counts[] = [$k, is_array($cache['data'][$k] ?? null) ? count($cache['data'][$k]) : 'missing'];
            }
            $io->table(['payload key', 'count'], $counts);
        }

        $io->section('Interpretation');
        $io->writeln('- If section 2 shows no type=magazine / type=reading-list rows, the events simply are not in the local DB (ingestion gap), or were saved without the expected "type" tag.');
        $io->writeln('- If section 3 counts are > 0 but section 4 counts are 0, the async worker has not rebuilt the cache yet — run: profile:flush-cache <npub> then reload.');
        $io->writeln('- If section 3 magazine entities is 0 but raw type=magazine rows exist in section 2, the MagazineProjector has not projected them yet.');

        return Command::SUCCESS;
    }

    /** @return array<int, array<int, mixed>> */
    private function decodeTags(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** @param array<int, array<int, mixed>> $tags */
    private function tagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === $name && isset($tag[1])) {
                return (string) $tag[1];
            }
        }
        return null;
    }

    /** @param array<int, array<int, mixed>> $tags */
    private function hasNestedIndex(array $tags, int $indexKind): bool
    {
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 'a' && str_starts_with((string) ($tag[1] ?? ''), $indexKind . ':')) {
                return true;
            }
        }
        return false;
    }
}
