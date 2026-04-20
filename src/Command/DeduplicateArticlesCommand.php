<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'articles:deduplicate', description: 'Mark duplicates with DO_NOT_INDEX.')]
class DeduplicateArticlesCommand extends Command
{
    private const BATCH_SIZE = 500;


    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->em->getRepository(Article::class);
        // Key: "pubkey:slug" -> bool. Deduplication is per author: two different
        // authors can legitimately share the same slug (e.g. "hello-world"), and
        // flagging one author's article as a duplicate of another's would evict
        // it from the search index and hide it from profile tabs / magazines.
        $seen = [];
        $page = 0;
        $flagged = 0;

        // Process articles in batches
        while (true) {
            // Fetch a batch of articles
            $articles = $repo->findBy([], ['createdAt' => 'DESC'], self::BATCH_SIZE, $page * self::BATCH_SIZE);

            if (empty($articles)) {
                break;
            }

            foreach ($articles as $article) {
                $pubkey = $article->getPubkey();
                $slug = $article->getSlug();
                $kind = $article->getKind();
                // Skip malformed rows — can't meaningfully dedup them
                if ($pubkey === null || $slug === null || $kind === null) {
                    continue;
                }
                $key = $pubkey . ':' . $slug . ':' . $kind->value;

                // If this (author, slug, kind) tuple hasn't been seen, keep it
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    continue;
                }
                // The articles are sorted by createdAt DESC, so the newest one is kept
                // Mark current (older) article as DO_NOT_INDEX
                $article->setIndexStatus(IndexStatusEnum::DO_NOT_INDEX);
                $flagged++;
            }

            // Flush the batch and clear memory to avoid overload
            $this->em->flush();
            $this->em->clear(); // Clear the entity manager to free up memory

            $output->writeln("Processed batch " . ($page + 1));
            $page++;
        }

        $output->writeln(sprintf('Article deduplication complete. Flagged %d duplicate(s) as DO_NOT_INDEX.', $flagged));
        return Command::SUCCESS;

    }

}
