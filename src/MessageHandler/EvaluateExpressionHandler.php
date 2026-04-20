<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ExpressionBundle\Service\ExpressionService;
use App\Message\EvaluateExpressionMessage;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class EvaluateExpressionHandler
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ExpressionService $expressionService,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(EvaluateExpressionMessage $message): void
    {
        $coordinate = "{$message->kind}:{$message->pubkey}:{$message->identifier}";

        $this->logger->info('Async expression evaluation started', [
            'coordinate' => $coordinate,
            'cacheKey' => $message->cacheKey,
        ]);

        $expression = $this->eventRepository->findByNaddr(
            $message->kind,
            $message->pubkey,
            $message->identifier,
        );

        if (!$expression) {
            $this->logger->warning('Expression not found for async evaluation', [
                'coordinate' => $coordinate,
            ]);
            $this->publishResult($message->cacheKey, 'error', 'Expression not found');
            return;
        }

        try {
            $results = $this->expressionService->evaluateCached($expression, $message->userPubkey, false);

            $this->logger->info('Async expression evaluation completed', [
                'coordinate' => $coordinate,
                'resultCount' => count($results),
            ]);

            $this->publishResult($message->cacheKey, 'ready', null, count($results));
        } catch (\Throwable $e) {
            $this->logger->error('Async expression evaluation failed', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);
            $this->publishResult($message->cacheKey, 'error', $e->getMessage());
        }
    }

    private function publishResult(string $cacheKey, string $status, ?string $error = null, ?int $count = null): void
    {
        try {
            $topic = sprintf('/expression-eval/%s', $cacheKey);
            $payload = ['status' => $status];
            if ($count !== null) {
                $payload['count'] = $count;
            }
            if ($error !== null) {
                $payload['error'] = $error;
            }
            $update = new Update($topic, json_encode($payload), false);
            $this->hub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for expression evaluation', [
                'cacheKey' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

