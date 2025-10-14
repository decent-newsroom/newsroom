<?php

namespace App\MessageHandler;

use App\Message\FetchCommentsMessage;
use App\Service\NostrClient;
use App\Service\NostrLinkParser;
use App\Service\RedisCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[AsMessageHandler]
class FetchCommentsHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly RedisCacheService $redisCacheService,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(FetchCommentsMessage $message): void
    {
        $coordinate = $message->getCoordinate();
        $comments = $this->nostrClient->getComments($coordinate);

        // Collect all pubkeys: authors and zappers
        $allPubKeys = [];
        foreach ($comments as $c) {
            $allPubKeys[] = $c->pubkey;
            if ($c->kind == 9735) {
                $tags = $c->tags ?? [];
                foreach ($tags as $tag) {
                    if ($tag[0] === 'p' && isset($tag[1])) {
                        $allPubKeys[] = $tag[1];
                    }
                }
            }
        }
        $allPubKeys = array_unique($allPubKeys);
        $authorsMetadata = $this->redisCacheService->getMultipleMetadata($allPubKeys);
        $this->logger->info('Fetched ' . count($comments) . ' comments for coordinate: ' . $coordinate);
        $this->logger->info('Fetched ' . count($authorsMetadata) . ' profiles for ' . count($allPubKeys) . ' pubkeys');

        usort($comments, fn($a, $b) => ($b->created_at ?? 0) <=> ($a->created_at ?? 0));
        // Optionally, reuse parseNostrLinks and parseZaps logic here if needed
        // For now, just send the raw comments array
        $data = [
            'coordinate' => $coordinate,
            'comments' => $comments,
            'profiles' => $authorsMetadata
        ];
        try {
            $topic = "/comments/" . $coordinate;
            $update = new Update($topic, json_encode($data), false);
            $this->logger->info('Publishing comments update for coordinate: ' . $coordinate);
            $this->hub->publish($update);
        } catch (\Exception $e) {
            // Handle exception (log it, etc.)
            $this->logger->error('Error publishing comments update: ' . $e->getMessage());
        }

    }
}
