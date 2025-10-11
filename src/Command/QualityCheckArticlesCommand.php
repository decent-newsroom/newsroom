<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Persister\ObjectPersister;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'articles:qa', description: 'Mark articles by quality and select which to index')]
class QualityCheckArticlesCommand extends Command
{
    private const BLACKLIST = [
        'npub1t5d8kcn0hu8zmt6dpkgatd5hwhx76956g7qmdzwnca6fzgprzlhqnqks86'
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    )
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $articles = $this->entityManager->getRepository(Article::class)->findBy(['indexStatus' => IndexStatusEnum::NOT_INDEXED]);
        $count = 0;
        foreach ($articles as $article) {
            if ($this->meetsCriteria($article)) {
                $count += 1;
                $article->setIndexStatus(IndexStatusEnum::TO_BE_INDEXED);
            } else {
                $article->setIndexStatus(IndexStatusEnum::DO_NOT_INDEX);
            }
            $this->entityManager->persist($article);
        }

        $this->entityManager->flush();

        $output->writeln($count . ' articles marked for indexing successfully.');

        return Command::SUCCESS;
    }

    private function meetsCriteria(Article $article): bool
    {
        // Exclude blacklisted pubkeys
        $key = new Key();
        if (in_array($key->convertPublicKeyToBech32($article->getPubkey()), self::BLACKLIST, true))
        {
            return false;
        }

        // Exclude articles with adult content tags
        $adultTags = ['nsfw', 'adult', 'explicit', '18+', 'nsfl'];
        $topics = $article->getTopics() ?? [];
        if (array_intersect($topics, $adultTags)) {
            return false;
        }

        $content = $article->getContent();

        // No empty title
        if (empty($article->getTitle()) || strtolower($article->getTitle()) === 'test' || strtolower($article->getTitle()) === 'step counter') {
            return false;
        }

        // Do not index stacker news reposts
        if (str_contains($content, 'originally posted at https://stacker.news')) {
            return false;
        }

        // Slug must not contain '/' and should not be empty
        if (empty($article->getSlug()) || str_contains($article->getSlug(), '/')) {
            return false;
        }

        // Only index articles with more than 12 words
        return str_word_count($article->getContent()) > 12;
    }
}
