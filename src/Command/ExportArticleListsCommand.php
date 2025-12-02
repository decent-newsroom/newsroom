<?php

namespace App\Command;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:export-article-lists',
    description: 'Export article event IDs and coordinates for relay ingest.'
)]
class ExportArticleListsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Query recent articles (last 7 days, kind 30023)
        $since = (new \DateTimeImmutable('-7 days'));
        $repo = $this->entityManager->getRepository(Article::class);
        $qb = $repo->createQueryBuilder('a')
            ->where('a.kind = :kind')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('kind', 30023)
            ->setParameter('since', $since);
        $articles = $qb->getQuery()->getResult();

        $eventIds = [];
        $coordinates = [];
        foreach ($articles as $article) {
            if (method_exists($article, 'getEventId')) {
                $eventIds[] = $article->getEventId();
            }
            // Build coordinate: "30023:<pubkey>:<d>"
            if (method_exists($article, 'getPubkey') && method_exists($article, 'getSlug')) {
                $coordinates[] = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
            }
        }

        // Output event IDs (first line)
        $output->writeln(json_encode($eventIds));
        // Output coordinates (second line)
        $output->writeln(json_encode($coordinates));

        return Command::SUCCESS;
    }
}

