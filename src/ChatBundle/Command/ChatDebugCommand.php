<?php

declare(strict_types=1);

namespace App\ChatBundle\Command;

use App\ChatBundle\Repository\ChatCommunityRepository;
use App\ChatBundle\Repository\ChatGroupRepository;
use App\ChatBundle\Repository\ChatUserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'chat:debug', description: 'Show chat module status')]
class ChatDebugCommand extends Command
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatUserRepository $userRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Chat Module Status');

        $communities = $this->communityRepo->findAll();
        $io->info(sprintf('Communities: %d', count($communities)));

        foreach ($communities as $community) {
            $groups = $this->groupRepo->findByCommunity($community);
            $users = $this->userRepo->findByCommunity($community);

            $io->section($community->getName() . ' (' . $community->getSubdomain() . ')');
            $io->listing([
                'Status: ' . $community->getStatus()->value,
                'Groups: ' . count($groups),
                'Users: ' . count($users),
                'Relay: ' . ($community->getRelayUrl() ?? 'default'),
            ]);
        }

        return Command::SUCCESS;
    }
}

