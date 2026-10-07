<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\IndexStatusEnum;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Service\MutedPubkeysService;
use App\Util\BlockedArticleDomainPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Elastica\Index;
use Elastica\Query\Terms;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'articles:qa', description: 'Mark articles by quality and select which to index')]
class QualityCheckArticlesCommand extends Command
{
    /** @var array<string, true> */
    private array $mutedPubkeys = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserEntityRepository $userRepository,
        private readonly Index $articleIndex,
        private readonly bool $elasticsearchEnabled,
        private readonly BlockedArticleDomainPolicy $blockedDomainPolicy,
        private readonly MutedPubkeysService $mutedPubkeysService,
    )
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Read the current admin mute list before approving any articles for indexing.
        try {
            $this->mutedPubkeys = array_fill_keys(
                array_map(strtolower(...), $this->userRepository->getMutedPubkeys()),
                true,
            );
            $this->muteBlockedDomainAuthors($output);
            $output->writeln(sprintf('Found %d muted users to exclude', count($this->mutedPubkeys)));
        } catch (\Exception $e) {
            $output->writeln('<error>Error checking blocked domains or fetching muted users: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Authors can be muted after their articles have already passed QA.
        if ($this->mutedPubkeys !== []) {
            if ($this->elasticsearchEnabled) {
                try {
                    // Remove stale documents before changing database status. A failed
                    // search deletion leaves the rows eligible for a retry.
                    $response = $this->articleIndex->deleteByQuery(
                        new Terms('pubkey', array_keys($this->mutedPubkeys)),
                        ['refresh' => true],
                    );
                    $result = $response->getData();
                    if (!empty($result['failures']) || !empty($result['timed_out'])) {
                        throw new \RuntimeException('Elasticsearch delete-by-query reported failures.');
                    }
                    $output->writeln(sprintf('Removed %d muted-user articles from Elasticsearch', (int) ($result['deleted'] ?? 0)));
                } catch (\Throwable $e) {
                    $output->writeln('<error>Error removing muted-user articles from Elasticsearch: ' . $e->getMessage() . '</error>');
                    return Command::FAILURE;
                }
            }

            $updated = $this->entityManager->createQueryBuilder()
                ->update(Article::class, 'a')
                ->set('a.indexStatus', ':blocked')
                ->where('LOWER(a.pubkey) IN (:pubkeys)')
                ->andWhere('(a.indexStatus IS NULL OR a.indexStatus != :blocked)')
                ->setParameter('blocked', IndexStatusEnum::DO_NOT_INDEX)
                ->setParameter('pubkeys', array_keys($this->mutedPubkeys))
                ->getQuery()
                ->execute();
            $output->writeln(sprintf('Marked %d existing muted-user articles as do_not_index', $updated));
        }

        $batchSize = 100;
        $count = 0;
        $processed = 0;

        // Process in batches — each batch fetches rows still matching NOT_INDEXED
        // because flush() changes them, the next batch gets fresh unprocessed rows.
        do {
            $articles = $this->entityManager->getRepository(Article::class)
                ->findBy(['indexStatus' => IndexStatusEnum::NOT_INDEXED], ['id' => 'ASC'], $batchSize);

            $batchCount = count($articles);

            foreach ($articles as $article) {
                if ($this->meetsCriteria($article)) {
                    $count++;
                    $article->setIndexStatus(IndexStatusEnum::TO_BE_INDEXED);
                } else {
                    $article->setIndexStatus(IndexStatusEnum::DO_NOT_INDEX);
                }
                $processed++;
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            if ($batchCount > 0) {
                $output->writeln(sprintf('Processed %d articles so far (%d marked for indexing)...', $processed, $count));
            }
        } while ($batchCount === $batchSize);

        $output->writeln(sprintf('%d articles processed, %d marked for indexing successfully.', $processed, $count));

        return Command::SUCCESS;
    }

    private function muteBlockedDomainAuthors(OutputInterface $output): void
    {
        $lastId = 0;
        $conditions = [];
        $parameters = ['draft' => KindsEnum::LONGFORM_DRAFT->value];
        foreach (BlockedArticleDomainPolicy::DOMAINS as $i => $domain) {
            $conditions[] = "LOWER(CONCAT_WS(' ', content, image, title, summary, raw::text)) LIKE :domain$i";
            $parameters['domain' . $i] = '%' . $domain . '%';
        }

        // Revisit matching articles regardless of QA status, including already indexed rows.
        $sql = 'SELECT id FROM article WHERE id > :lastId AND (kind IS NULL OR kind != :draft) AND ('
            . implode(' OR ', $conditions) . ') ORDER BY id ASC LIMIT 100';
        do {
            $ids = $this->entityManager->getConnection()
                ->executeQuery($sql, $parameters + ['lastId' => $lastId])
                ->fetchFirstColumn();
            if ($ids === []) {
                break;
            }
            $lastId = (int) end($ids);
            $articles = $this->entityManager->getRepository(Article::class)->findBy(['id' => $ids]);
            $changed = false;

            foreach ($articles as $article) {
                $domain = $this->blockedDomainPolicy->findBlockedDomain($article);
                $hex = strtolower(trim($article->getPubkey() ?? ''));
                if ($domain === null || isset($this->mutedPubkeys[$hex])) {
                    continue;
                }
                $npub = PublicKey::fromHex($hex)?->toBech32()
                    ?? throw new \InvalidArgumentException(sprintf('Invalid pubkey on blocked-domain article %d', $article->getId()));
                $user = $this->userRepository->findOneBy(['npub' => $npub]);
                if ($user === null) {
                    $user = new User();
                    $user->setNpub($npub);
                    $this->entityManager->persist($user);
                }
                $user->addRole(RolesEnum::MUTED->value);
                $this->mutedPubkeys[$hex] = true;
                $changed = true;
                $output->writeln(sprintf('Admin-muting %s: article %d contains blocked domain %s', $hex, $article->getId(), $domain));
            }

            $this->entityManager->flush();
            if ($changed) {
                $this->mutedPubkeysService->invalidateCache();
            }
            $this->entityManager->clear();
        } while (count($ids) === 100);
    }

    private function meetsCriteria(Article $article): bool
    {
        if ($this->blockedDomainPolicy->findBlockedDomain($article) !== null) {
            return false;
        }

        // Exclude admin-muted authors using the same hex pubkey as Article.
        if (isset($this->mutedPubkeys[strtolower(trim($article->getPubkey()))])) {
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

        // Skip articles where title begins with
        // EA://NEWS
        if (str_starts_with(trim($title), 'EA://NEWS')) {
            return false;
        }

        // Skip articles that contain 'referral code' in the title, case insensitive
        if (str_contains(strtolower($title), 'referral code')) {
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
