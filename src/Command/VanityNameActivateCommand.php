<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\VanityNameRepository;
use App\Service\VanityNameService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually activate a vanity name after verifying payment.
 * Use this command after confirming the user has paid their invoice.
 */
#[AsCommand(
    name: 'vanity:activate',
    description: 'Manually activate a vanity name after payment verification'
)]
class VanityNameActivateCommand extends Command
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
        private readonly VanityNameRepository $vanityNameRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The vanity name to activate')
            ->setHelp(
                'This command manually activates a pending vanity name. ' .
                'Use this after verifying payment has been received.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        $io->title('Vanity Name Activation');

        // Find the vanity name
        $vanityName = $this->vanityNameRepository->findByVanityName($name);

        if ($vanityName === null) {
            $io->error(sprintf('Vanity name "%s" not found.', $name));
            return Command::FAILURE;
        }

        // Show current details
        $io->section('Current Status');
        $io->table(
            ['Field', 'Value'],
            [
                ['Vanity Name', $vanityName->getVanityName()],
                ['Npub', $vanityName->getNpub()],
                ['Status', $vanityName->getStatus()->getLabel()],
                ['Payment Type', $vanityName->getPaymentType()->getLabel()],
                ['Created', $vanityName->getCreatedAt()->format('Y-m-d H:i:s')],
            ]
        );

        if ($vanityName->getStatus()->isActive()) {
            $io->info('This vanity name is already active.');
            $io->table(
                ['Field', 'Value'],
                [
                    ['Expires', $vanityName->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'Lifetime'],
                    ['Days Remaining', $vanityName->getDaysRemaining() ?? 'N/A'],
                ]
            );
            return Command::SUCCESS;
        }

        // Confirm activation
        if (!$io->confirm('Activate this vanity name?', false)) {
            $io->info('Activation cancelled.');
            return Command::SUCCESS;
        }

        try {
            $this->vanityNameService->activate($vanityName);

            $io->success(sprintf('Vanity name "%s" activated successfully!', $name));
            $io->table(
                ['Field', 'Value'],
                [
                    ['Status', $vanityName->getStatus()->getLabel()],
                    ['Expires', $vanityName->getExpiresAt()?->format('Y-m-d H:i:s') ?? 'Lifetime'],
                    ['NIP-05', $vanityName->getVanityName() . '@' . $this->vanityNameService->getServerDomain()],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to activate: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

