<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\VanityNameStatus;
use App\Repository\VanityNameRepository;
use App\Service\VanityNameService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List vanity names with optional status filter.
 */
#[AsCommand(
    name: 'vanity:list',
    description: 'List vanity names'
)]
class VanityNameListCommand extends Command
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
        private readonly VanityNameRepository $vanityNameRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'status',
                's',
                InputOption::VALUE_OPTIONAL,
                'Filter by status (pending, active, suspended, released)'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of results',
                '50'
            )
            ->setHelp('List all vanity names, optionally filtered by status.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $statusFilter = $input->getOption('status');
        $limit = (int) $input->getOption('limit');

        $io->title('Vanity Names');

        // Get stats
        $allNames = $this->vanityNameRepository->findAll();
        $stats = [
            'total' => count($allNames),
            'active' => 0,
            'pending' => 0,
            'suspended' => 0,
            'released' => 0,
        ];

        foreach ($allNames as $v) {
            $stats[$v->getStatus()->value]++;
        }

        $io->section('Statistics');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total Vanity Names', $stats['total']],
                ['Active', $stats['active']],
                ['Pending Payment', $stats['pending']],
                ['Suspended', $stats['suspended']],
                ['Released', $stats['released']],
            ]
        );

        // Get filtered list
        $status = null;
        if ($statusFilter) {
            $status = VanityNameStatus::tryFrom($statusFilter);
            if ($status === null) {
                $io->warning("Invalid status: {$statusFilter}. Valid values: pending, active, suspended, released");
            }
        }

        $vanityNames = $this->vanityNameService->getAll($status);

        if (count($vanityNames) > $limit) {
            $vanityNames = array_slice($vanityNames, 0, $limit);
            $io->note("Showing first {$limit} results.");
        }

        if (empty($vanityNames)) {
            $io->info('No vanity names found.');
            return Command::SUCCESS;
        }

        $io->section($status ? ucfirst($status->value) . ' Vanity Names' : 'All Vanity Names');

        $rows = [];
        foreach ($vanityNames as $vanityName) {
            $expires = $vanityName->getExpiresAt();
            $expiresStr = $expires ? $expires->format('Y-m-d') : 'Lifetime';
            if ($expires && $vanityName->isExpired()) {
                $expiresStr .= ' (EXPIRED)';
            } elseif ($expires && $vanityName->getDaysRemaining() <= 7) {
                $expiresStr .= ' (' . $vanityName->getDaysRemaining() . 'd)';
            }

            $rows[] = [
                $vanityName->getVanityName(),
                substr($vanityName->getNpub(), 0, 15) . '...',
                $vanityName->getStatus()->value,
                $vanityName->getPaymentType()->value,
                $expiresStr,
                $vanityName->getCreatedAt()->format('Y-m-d'),
            ];
        }

        $io->table(
            ['Name', 'Npub', 'Status', 'Payment', 'Expires', 'Created'],
            $rows
        );

        // Show pending with instructions
        if ($stats['pending'] > 0) {
            $io->section('Pending Vanity Names');
            $pending = $this->vanityNameRepository->findPending();
            foreach (array_slice($pending, 0, 5) as $p) {
                $io->text(sprintf(
                    '  • %s (%s) - Created %s',
                    $p->getVanityName(),
                    substr($p->getNpub(), 0, 15) . '...',
                    $p->getCreatedAt()->format('Y-m-d H:i')
                ));
            }
            $io->newLine();
            $io->note('Use: php bin/console vanity:activate <name> to manually activate after payment');
        }

        return Command::SUCCESS;
    }
}

