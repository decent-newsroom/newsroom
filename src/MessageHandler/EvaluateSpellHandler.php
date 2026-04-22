<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ExpressionBundle\Logging\LoggerSwitch;
use App\ExpressionBundle\Logging\MercureProgressLogger;
use App\ExpressionBundle\Logging\TeeLogger;
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
        private readonly ?LoggerSwitch $loggerSwitch = null,
    ) {}

    public function __invoke(EvaluateSpellMessage $message): void
    {
        $topic = sprintf('/spell-eval/%s', $message->cacheKey);

        $progress = new TeeLogger(
            $this->logger,
            new MercureProgressLogger($this->hub, $topic, $this->logger),
        );
        $this->loggerSwitch?->push($progress);

        try {
            $progress->info('Async spell evaluation started', [
                'eventId' => $message->spellEventId,
                'cacheKey' => $message->cacheKey,
            ]);

            $spell = $this->eventRepository->find($message->spellEventId);
            if (!$spell || $spell->getKind() !== 777) {
                $progress->warning('Spell not found for async evaluation', [
                    'eventId' => $message->spellEventId,
                ]);
                $this->publishResult($message->cacheKey, 'error', 'Spell not found');
                return;
            }

            try {
                $results = $this->expressionService->evaluateSpellCached($spell, $message->userPubkey);

                $progress->info('Async spell evaluation completed', [
                    'eventId' => $message->spellEventId,
                    'resultCount' => count($results),
                ]);

                $this->publishResult($message->cacheKey, 'ready', null, count($results));
            } catch (\Throwable $e) {
                $progress->error('Async spell evaluation failed', [
                    'eventId' => $message->spellEventId,
                    'error' => $e->getMessage(),
                ]);
                $this->publishResult($message->cacheKey, 'error', $e->getMessage());
            }
        } finally {
            $this->loggerSwitch?->pop();
        }
    }

    private function publishResult(string $cacheKey, string $status, ?string $error = null, ?int $count = null): void
    {
        $topic = sprintf('/spell-eval/%s', $cacheKey);
        try {
            $payload = ['status' => $status];
            if ($count !== null) {
                $payload['count'] = $count;
            }
            if ($error !== null) {
                $payload['error'] = $error;
            }
            $this->hub->publish(new Update($topic, json_encode($payload), false));
        } catch (\Throwable $e) {
            $this->logger->error('Mercure publish failed for spell evaluation', [
                'topic' => $topic,
                'cacheKey' => $cacheKey,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
