<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ArticleRepository;
use App\Util\NostrKeyUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mark or unmark an article (by NIP-23 coordinate) as Essayist-exclusive.
 *
 * Coordinate accepted formats:
 *   - <npub_or_hexpubkey> <slug>   (two positional args)
 *   - 30023:<hexpubkey>:<slug>     (single arg, classic NIP-01 coordinate)
 *
 * Flips the flag on every revision row for the (pubkey, slug) pair, so
 * future re-projections of older revisions keep the exclusive flag.
 */
#[AsCommand(
    name: 'essayist:mark-exclusive',
    description: 'Flag an article as Essayist-exclusive so non-members never receive it'
)]
final class EssayistMarkExclusiveCommand extends Command
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('author', InputArgument::REQUIRED, 'Author npub, hex pubkey, or full 30023:pubkey:slug coordinate')
            ->addArgument('slug', InputArgument::OPTIONAL, 'Article d-tag (slug). Omit when passing a full coordinate as the first argument.')
            ->addOption('unmark', null, InputOption::VALUE_NONE, 'Clear the flag instead of setting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $author  = (string) $input->getArgument('author');
        $slug    = $input->getArgument('slug');
        $exclusive = !$input->getOption('unmark');

        // Allow the classic NIP-01 coordinate as a single argument.
        if ($slug === null && str_contains($author, ':')) {
            $parts = explode(':', $author, 3);
            if (count($parts) !== 3) {
                $io->error('Coordinate must look like "30023:<hexpubkey>:<slug>".');
                return Command::INVALID;
            }
            [, $author, $slug] = $parts;
        }

        if (!is_string($slug) || $slug === '') {
            $io->error('Missing article slug (d-tag).');
            return Command::INVALID;
        }

        try {
            $pubkey = NostrKeyUtil::isNpub($author)
                ? NostrKeyUtil::npubToHex($author)
                : $author;
        } catch (\Throwable $e) {
            $io->error('Could not resolve author identifier: ' . $e->getMessage());
            return Command::INVALID;
        }

        if (!NostrKeyUtil::isHexPubkey($pubkey)) {
            $io->error('Author must be an npub or 64-char hex pubkey.');
            return Command::INVALID;
        }

        $updated = $this->articleRepository->setEssayistExclusiveByCoordinate($pubkey, $slug, $exclusive);

        if ($updated === 0) {
            $io->warning(sprintf(
                'No article rows matched coordinate 30023:%s:%s — nothing changed.',
                $pubkey,
                $slug
            ));
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s %d article row(s) for 30023:%s:%s',
            $exclusive ? 'Flagged' : 'Cleared',
            $updated,
            $pubkey,
            $slug
        ));

        return Command::SUCCESS;
    }
}

