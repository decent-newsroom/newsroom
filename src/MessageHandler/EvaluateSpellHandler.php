<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ExpressionBundle\Service\ExpressionService;
use App\Message\EvaluateSpellMessage;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EvaluateSpellHandler
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ExpressionService $expressionService,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(EvaluateSpellMessage $message): void
    {
        $this->logger->info('Async spell evaluation started', [
            'eventId' => $message->spellEventId,
            'cacheKey' => $message->cacheKey,
        ]);

        $spell = $this->eventRepository->find($message->spellEventId);
        if (!$spell || $spell->getKind() !== 777) {
            $this->logger->warning('Spell not found for async evaluation', [
                'eventId' => $message->spellEventId,
            ]);
            $this->publishResult($message->cacheKey, 'error', 'Spell not found');
            return;
        }

        try {
            $results = $this->expressionService->evaluateSpellCached($spell, $message->userPubkey);

            $this->logger->info('Async spell evaluation completed', [
                'eventId' => $message->spellEventId,
                'resultCount' => count($results),
            ]);

            $this->publishResult($message->cacheKey, 'ready', null, count($results));
        } catch (\Throwable $e) {
            $this->logger->error('Async spell evaluation failed', [
                'eventId' => $message->spellEventId,
                'error' => $e->getMessage(),
            ]);
            $this->publishResult($message->cacheKey, 'error', $e->getMessage());
        }
    }

    private function publishResult(string $cacheKey, string $status, ?string $error = null, ?int $count = null): void
    {
        try {
            $topic = sprintf('/spell-eval/%s', $cacheKey);
            $payload = ['status' => $status];
            if ($count !== null) {
                $payload['count'] = $count;
            }
            if ($error !== null) {
                $payload['error'] = $error;
            }
            $this->hub->publish(new Update($topic, json_encode($payload), false));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for spell evaluation', [
                'cacheKey' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

