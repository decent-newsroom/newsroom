<?php

namespace App\Command;

use App\Entity\Article;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Exception\CommonMarkException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'articles:process-html',
    description: 'Process and cache HTML for articles that are missing processed HTML content'
)]
class ProcessArticleHtmlCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Converter $converter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reprocessing of all articles (including those with existing HTML)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit the number of articles to process', null)
            ->setHelp(
                'This command processes markdown content to HTML for articles and caches the result in the database. ' .
                'By default, it only processes articles that are missing processed HTML. ' .
                'Use --force to reprocess all articles.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $limit = $input->getOption('limit');

        $io->title('Article HTML Processing');

        // Build query
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Article::class, 'a')
            ->where('a.content IS NOT NULL');

        if (!$force) {
            $queryBuilder->andWhere('a.processedHtml IS NULL');
        }

        if ($limit) {
            $queryBuilder->setMaxResults((int) $limit);
        }

        $articles = $queryBuilder->getQuery()->getResult();
        $total = count($articles);

        if ($total === 0) {
            $io->success('No articles to process.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d article(s) to process', $total));
        $io->newLine();

        $progressBar = $io->createProgressBar($total);
        $progressBar->setFormat('very_verbose');
        $progressBar->start();

        $processed = 0;
        $failed = 0;
        $batchSize = 20;


        foreach ($articles as $index => $article) {
            try {
                $html = $this->converter->convertToHTML($article->getContent());
                /* @var Article $article */
                $article->setProcessedHtml($html);
                $this->entityManager->persist($article);
                $processed++;

                // Flush in batches for better performance
                if (($index + 1) % $batchSize === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            } catch (\Exception|CommonMarkException $e) {
                $failed++;
                $io->writeln('');
                $io->warning(sprintf(
                    'Failed to process article %s: %s',
                    $article->getEventId() ?? $article->getId(),
                    $e->getMessage()
                ));
            }

            $progressBar->advance();
        }

        // Final flush for remaining articles
        $this->entityManager->flush();
        $this->entityManager->clear();

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $io->success(sprintf(
            'Processing complete: %d processed, %d failed',
            $processed,
            $failed
        ));

        if ($failed > 0) {
            $io->note('Some articles failed to process. Check the warnings above for details.');
        }

        return Command::SUCCESS;
    }
}

