<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\VanityNameService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Process expired vanity name subscriptions.
 * Should be run via cron daily.
 */
#[AsCommand(
    name: 'vanity:process-expired',
    description: 'Process expired vanity name subscriptions'
)]
class VanityNameProcessExpiredCommand extends Command
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'This command releases expired vanity name subscriptions. ' .
            'Run this daily via cron to automatically clean up expired reservations.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Vanity Name Expiration Processor');

        try {
            $count = $this->vanityNameService->processExpired();

            if ($count > 0) {
                $io->success(sprintf('Released %d expired vanity name(s).', $count));
            } else {
                $io->info('No expired vanity names to process.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error processing expired vanity names: ' . $e->getMessage());
            $this->logger->error('Vanity name expiration processing failed', [
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }
}

