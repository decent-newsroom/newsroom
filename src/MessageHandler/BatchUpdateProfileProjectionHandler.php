<?php

namespace App\MessageHandler;

use App\Message\BatchUpdateProfileProjectionMessage;
use App\Message\UpdateProfileProjectionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles batch profile projection updates by dispatching individual update messages.
 */
#[AsMessageHandler]
class BatchUpdateProfileProjectionHandler
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(BatchUpdateProfileProjectionMessage $message): void
    {
        $pubkeys = $message->getPubkeyHexList();

        $this->logger->info('Processing batch profile projection update', [
            'count' => count($pubkeys)
        ]);

        $dispatched = 0;
        foreach ($pubkeys as $pubkeyHex) {
            try {
                $this->messageBus->dispatch(new UpdateProfileProjectionMessage($pubkeyHex));
                $dispatched++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to dispatch profile update', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Batch profile projection dispatch complete', [
            'dispatched' => $dispatched,
            'total' => count($pubkeys)
        ]);
    }
}
