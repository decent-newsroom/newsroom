<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

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
            // Log the error but don't let it kill the worker
            $this->logger->warning('TransportException caught and ignored', [
                'exception' => $e->getMessage(),
                'message_class' => get_class($envelope->getMessage()),
            ]);

            // Return the envelope to continue processing
            return $envelope;
        }
    }
}
