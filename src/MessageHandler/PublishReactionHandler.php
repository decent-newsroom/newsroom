<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PublishReactionMessage;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\RelayPublishResult;
use Innis\Nostr\Core\Domain\Entity\Event as NostrEvent;
use Psr\Log\LoggerInterface;
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

        $eventObj = NostrEvent::fromArray($signedEvent);

        $relayResults = $this->nostrClient->publishEvent($eventObj, $relays);

        $successCount = 0;
        $failCount = 0;
        foreach ($relayResults as $result) {
            $isSuccess = RelayPublishResult::isSuccessful($result);
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
