<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\IndexStatusEnum;
use App\Repository\UserEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'articles:qa', description: 'Mark articles by quality and select which to index')]
class QualityCheckArticlesCommand extends Command
{
    private array $mutedUserNpubs = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserEntityRepository $userRepository
    )
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Load muted users
        try {
            $mutedUsers = $this->userRepository->findMutedUsers();
            $this->mutedUserNpubs = array_map(fn(User $user) => $user->getNpub(), $mutedUsers);
            $output->writeln(sprintf('Found %d muted users to exclude', count($this->mutedUserNpubs)));
        } catch (\Exception $e) {
            // Notify and continue
            $output->writeln('<error>Error fetching muted users: ' . $e->getMessage() . '</error>');
        }

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
        // Exclude muted users
        $key = new Key();
        $authorNpub = $key->convertPublicKeyToBech32($article->getPubkey());
        if (in_array($authorNpub, $this->mutedUserNpubs, true))
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
        $title = $article->getTitle();

        // No empty, null, or "null" string titles
        if (empty($title) ||
            strtolower(trim($title)) === 'null' ||
            strtolower($title) === 'test' ||
            strtolower($title) === 'step counter') {
            return false;
        }

        // Skip articles with stringified JSON content (malformed kind 30023 events)
        if ($content && (str_starts_with(trim($content), '{') || str_starts_with(trim($content), '['))) {
            // Try to decode JSON - if it succeeds, it's likely stringified JSON instead of proper content
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return false;
            }
        }

        // Do not index stacker news reposts
        // Filter out articles that end with stacker.news link
        if (preg_match('/https:\/\/stacker\.news\/items\/\d+$/', $content)) {
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
