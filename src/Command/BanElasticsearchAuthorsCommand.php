<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Admin\AuthorBanService;
use App\Service\Admin\ElasticsearchAuthorBanList;
use App\Service\Admin\PubkeyContentPurger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'admin:ban-es-authors',
    description: 'Permanently ban every author in an Elasticsearch hits JSON export and purge all their stored content.',
)]
class BanElasticsearchAuthorsCommand extends Command
{
    public function __construct(
        private readonly ElasticsearchAuthorBanList $banList,
        private readonly AuthorBanService $bans,
        private readonly PubkeyContentPurger $purger,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to an Elasticsearch search response JSON file inside the container')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason retained with each permanent ban', 'Spam authors imported from Elasticsearch')
            ->addOption('added-by', null, InputOption::VALUE_REQUIRED, 'Operator identifier for the audit record', 'admin:ban-es-authors')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List target authors and local counts without making changes')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm banning authors and deleting all of their content without a prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $response = json_decode((new Filesystem())->readFile($input->getArgument('file')), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($response)) {
                throw new \InvalidArgumentException('The export must contain a JSON object.');
            }
            $pubkeys = $this->banList->extractPubkeys($response);
            $reason = trim($input->getOption('reason'));
            $addedBy = trim($input->getOption('added-by'));
            if ($reason === '' || $addedBy === '' || strlen($addedBy) > 255) {
                throw new \InvalidArgumentException('Provide a nonempty reason and an operator identifier of at most 255 bytes.');
            }
            if ($pubkeys === []) {
                $io->info('No authors in this export. Nothing changed.');
                return Command::SUCCESS;
            }
            $hits = count($response['hits']['hits']);
            $total = $response['hits']['total'] ?? $hits;
            $totalValue = is_array($total) ? ($total['value'] ?? $hits) : $total;
            if ($totalValue > $hits || (is_array($total) && ($total['relation'] ?? '') === 'gte')) {
                $io->warning(sprintf('This export contains %d hits, not every search match. Only authors present in this file will be banned.', $hits));
            }
            $counts = $this->purger->counts($pubkeys);
            $io->section(sprintf('%d unique authors from %d exported hits', count($pubkeys), $hits));
            $io->listing($pubkeys);
            $io->table(['Local store', 'Rows to delete'], array_map(
                static fn (string $table, int $count): array => [$table, $count],
                array_keys($counts), array_values($counts),
            ));
            $io->warning('Author-wide operation: this removes ALL stored content from these authors, not just the exported hits. Bans also block all future event kinds and article revisions.');
            if ($input->getOption('dry-run')) {
                $io->success('Dry run: no bans, mutes, cache invalidations, or deletions performed.');
                return Command::SUCCESS;
            }
            if (!$input->getOption('confirm') && !$io->confirm('Permanently ban these authors and purge all their content?', false)) {
                $io->info('Aborted. Nothing changed.');
                return Command::SUCCESS;
            }

            // Commit bans first: a failed cleanup must never reopen ingestion.
            $this->bans->ban($pubkeys, $reason, $addedBy);
            $deleted = $this->purger->purge($pubkeys);
            $io->success(sprintf(
                'Banned and admin-muted %d authors; removed %d Elasticsearch documents and %d local rows.',
                count($pubkeys), $deleted['elasticsearch'], array_sum($deleted) - $deleted['elasticsearch'],
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Author ban import or purge failed', ['error' => $e->getMessage()]);
            $io->error($e->getMessage());
            $io->note('If bans were already committed, they remain active. Rerun the same export to retry cleanup safely.');

            return Command::FAILURE;
        }
    }
}
