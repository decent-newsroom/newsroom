<?php

namespace App\MessageHandler;

use App\Message\FetchAuthorArticlesMessage;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\SerializerInterface;

#[AsMessageHandler]
class FetchAuthorArticlesHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
        private readonly HubInterface $hub,
        private readonly SerializerInterface $serializer
    ) {}

    public function __invoke(FetchAuthorArticlesMessage $message): void
    {
        $pubkey = $message->getPubkey();
        $since = $message->getSince();

        $this->logger->info('Fetching new articles for author since timestamp', [
            'pubkey' => $pubkey,
            'since' => $since
        ]);

        try {
            $articles = $this->nostrClient->getLongFormContentForPubkey($pubkey, $since);
            $this->logger->info('Fetched and saved new articles for author', [
                'pubkey' => $pubkey,
                'count' => count($articles)
            ]);

            // Deduplicate by slug (keep latest)
            // Sort articles by created_at descending
            usort($articles, function($a, $b) {
                return $b->getCreatedAt()->getTimestamp() <=> $a->getCreatedAt()->getTimestamp();
            });
            $uniqueArticles = [];
            foreach ($articles as $article) {
                $uniqueArticles[$article->getSlug()] = $article;
            }
            // Only keep articles with id !== null
            $uniqueArticles = array_filter($uniqueArticles, fn($a) => $a->getId() !== null);
            // Re-index array
            $uniqueArticles = array_values($uniqueArticles);
            // Serialize articles to arrays
            $articleData = array_map(function($article) {
                return [
                    'id' => $article->getId(),
                    'slug' => $article->getSlug(),
                    'title' => $article->getTitle(),
                    'content' => $article->getContent(),
                    'summary' => $article->getSummary(),
                    'createdAt' => $article->getCreatedAt()->getTimestamp(),
                    'pubkey' => $article->getPubkey(),
                    'image' => $article->getImage(),
                    'topics' => $article->getTopics(),
                ];
            }, $uniqueArticles);

            // Publish updates to Mercure
            $update = new Update(
                '/articles/' . $pubkey,
                json_encode(['articles' => $articleData]),
                false
            );
            $this->logger->info('Publishing articles update for pubkey: ' . $pubkey);
            $this->hub->publish($update);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching new articles for author', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage()
            ]);
        }
    }
}
