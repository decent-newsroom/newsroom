<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PublishReactionMessage;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PublishReactionHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishReactionMessage $message): void
    {
        $signedEvent = $message->getSignedEvent();
        $relays = $message->getRelays();

        if ($relays === []) {
            return;
        }

        $eventObj = new NostrEvent();
        $eventObj->setId((string) ($signedEvent['id'] ?? ''));
        $eventObj->setPublicKey((string) ($signedEvent['pubkey'] ?? ''));
        $eventObj->setCreatedAt((int) ($signedEvent['created_at'] ?? 0));
        $eventObj->setKind((int) ($signedEvent['kind'] ?? 0));
        $eventObj->setTags(is_array($signedEvent['tags'] ?? null) ? $signedEvent['tags'] : []);
        $eventObj->setContent((string) ($signedEvent['content'] ?? ''));
        $eventObj->setSignature((string) ($signedEvent['sig'] ?? ''));

        $relayResults = $this->nostrClient->publishEvent($eventObj, $relays);

        $successCount = 0;
        $failCount = 0;
        foreach ($relayResults as $result) {
            $isSuccess = $result === true || (is_object($result) && isset($result->type) && $result->type === 'OK');
            $isSuccess ? $successCount++ : $failCount++;
        }

        $this->logger->info('Broadcast article reaction to relays', [
            'event_id' => $signedEvent['id'] ?? null,
            'relay_count' => count($relays),
            'success' => $successCount,
            'failed' => $failCount,
        ]);
    }
}
