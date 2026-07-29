<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserEntityRepository;
use App\Util\NostrKeyUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print all currently muted users (ROLE_MUTED) as hex pubkeys, one per line.
 *
 * Output is intentionally plain (no styling, no headers) so it can be copied
 * straight into the strfry blocklist file (/etc/strfry-blocked-pubkeys.txt),
 * which expects one hex pubkey per line.
 *
 * Usage:
 *   docker compose exec php bin/console admin:list-muted-pubkeys
 *   docker compose exec php bin/console admin:list-muted-pubkeys >> strfry-blocked-pubkeys.txt
 */
#[AsCommand(
    name: 'admin:list-muted-pubkeys',
    description: 'List all muted users (ROLE_MUTED) as hex pubkeys, one per line, for the strfry blocklist',
)]
class ListMutedPubkeysCommand extends Command
{
    public function __construct(
        private readonly UserEntityRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pubkeys = [];
        foreach ($this->userRepository->findMutedUsers() as $user) {
            $npub = $user->getNpub();
            if ($npub === null || ! NostrKeyUtil::isNpub($npub)) {
                continue;
            }

            try {
                $pubkeys[] = NostrKeyUtil::npubToHex($npub);
            } catch (\Throwable) {
                // Skip unparseable npubs silently to keep output clean.
            }
        }

        foreach (array_unique($pubkeys) as $hex) {
            $output->writeln($hex);
        }

        return Command::SUCCESS;
    }
}
