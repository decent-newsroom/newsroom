<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ProjectMagazineMessage;
use Psr\Log\LoggerInterface;
use Redis as RedisClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:project-magazines',
    description: 'Project magazine indices from Nostr events to Magazine entities',
)]
class ProjectMagazinesCommand extends Command
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Specific magazine slug to project')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force projection even if recently updated')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $specificSlug = $input->getArgument('slug');
        $force = $input->getOption('force');

        if ($specificSlug) {
            // Project a specific magazine
            $io->info("Dispatching projection for magazine: $specificSlug");
            $this->messageBus->dispatch(new ProjectMagazineMessage($specificSlug, $force));
            $io->success('Projection message dispatched');
            return Command::SUCCESS;
        }

        // Read all magazine slugs from Redis set
        try {
            $slugs = $this->redis->sMembers('magazine_slugs');

            if (empty($slugs)) {
                $io->warning('No magazine slugs found in Redis');
                return Command::SUCCESS;
            }

            $io->info("Found " . count($slugs) . " magazine(s) to project");

            foreach ($slugs as $slug) {
                $io->writeln("  - Dispatching: $slug");
                $this->messageBus->dispatch(new ProjectMagazineMessage($slug, $force));
                $this->logger->info('Dispatched magazine projection', ['slug' => $slug]);
            }

            $io->success("Dispatched projection messages for " . count($slugs) . " magazine(s)");

        } catch (\Throwable $e) {
            $io->error("Failed to read magazine slugs: " . $e->getMessage());
            $this->logger->error('Magazine projection command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
