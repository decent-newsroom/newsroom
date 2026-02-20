<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:list-commented-articles',
    description: 'List articles that have comments (kind 1111) or zaps (kind 9735) in the database',
)]
class ListCommentedArticlesCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Extract the coordinate from the 'A' tag of each comment/zap event,
        // then count how many comments vs zaps per coordinate.
        $sql = <<<'SQL'
            SELECT
                tag->>1                                          AS coordinate,
                COUNT(*) FILTER (WHERE e.kind = 1111)            AS comments,
                COUNT(*) FILTER (WHERE e.kind = 9735)            AS zaps
            FROM event e,
                 jsonb_array_elements(e.tags::jsonb) AS tag
            WHERE e.kind IN (1111, 9735)
              AND tag->>0 = 'A'
            GROUP BY tag->>1
            ORDER BY (COUNT(*) FILTER (WHERE e.kind = 1111)) DESC
        SQL;

        $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();

        if (empty($rows)) {
            $io->info('No comments or zaps found in the database.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Articles with comments/zaps (%d)', count($rows)));

        $io->table(
            ['Coordinate', 'Comments', 'Zaps'],
            array_map(fn($r) => [$r['coordinate'], $r['comments'], $r['zaps']], $rows),
        );

        return Command::SUCCESS;
    }
}

