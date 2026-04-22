<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ExpressionBundle\Logging\LoggerSwitch;
use App\ExpressionBundle\Logging\MercureProgressLogger;
use App\ExpressionBundle\Logging\TeeLogger;
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
        private readonly ?LoggerSwitch $loggerSwitch = null,
    ) {}

    public function __invoke(EvaluateExpressionMessage $message): void
    {
        $coordinate = "{$message->kind}:{$message->pubkey}:{$message->identifier}";
        $topic = sprintf('/expression-eval/%s', $message->cacheKey);

        // Tee every PSR-3 record from the bundle pipeline to Mercure so the
        // browser can render live progress while we evaluate.
        $progress = new TeeLogger(
            $this->logger,
            new MercureProgressLogger($this->hub, $topic, $this->logger),
        );
        $this->loggerSwitch?->push($progress);

        try {
            $progress->info('Async expression evaluation started', [
                'coordinate' => $coordinate,
                'cacheKey' => $message->cacheKey,
            ]);

            $expression = $this->eventRepository->findByNaddr(
                $message->kind,
                $message->pubkey,
                $message->identifier,
            );

            if (!$expression) {
                $progress->warning('Expression not found for async evaluation', [
                    'coordinate' => $coordinate,
                ]);
                $this->publishResult($message->cacheKey, 'error', 'Expression not found');
                return;
            }

            try {
                $results = $this->expressionService->evaluateCached($expression, $message->userPubkey, false);

                $progress->info('Async expression evaluation completed', [
                    'coordinate' => $coordinate,
                    'resultCount' => count($results),
                ]);

                $this->publishResult($message->cacheKey, 'ready', null, count($results));
            } catch (\Throwable $e) {
                $progress->error('Async expression evaluation failed', [
                    'coordinate' => $coordinate,
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
        $topic = sprintf('/expression-eval/%s', $cacheKey);
        try {
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
            // Escalated from warning → error: a silently-failing publish was
            // the prime suspect for "Mercure is not getting any updates".
            $this->logger->error('Mercure publish failed for expression evaluation', [
                'topic' => $topic,
                'cacheKey' => $cacheKey,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
