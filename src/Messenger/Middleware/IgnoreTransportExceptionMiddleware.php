<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Middleware that catches and logs TransportException errors
 * (e.g., "Could not acknowledge redis message") without killing the worker.
 */
class IgnoreTransportExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (TransportException $e) {
            $messageClass = get_class($envelope->getMessage());

            // Check if message has been redelivered multiple times
            $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
            $retryCount = $redeliveryStamp ? $redeliveryStamp->getRetryCount() : 0;

            // Log the error but don't let it kill the worker
            $this->logger->warning('TransportException caught and ignored', [
                'exception' => $e->getMessage(),
                'message_class' => $messageClass,
                'retry_count' => $retryCount,
                'trace' => substr($e->getTraceAsString(), 0, 500), // Limit trace length
            ]);

            // If this is a Redis acknowledgment error, it's likely the message
            // was already processed or removed by another worker
            if (str_contains($e->getMessage(), 'Could not acknowledge')) {
                $this->logger->info('Message likely already acknowledged by another worker', [
                    'message_class' => $messageClass,
                ]);
            }

            // Return the envelope to continue processing
            return $envelope;
        }
    }
}
