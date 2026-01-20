<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProjectMagazineMessage;
use App\Service\MagazineProjector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProjectMagazineMessageHandler
{
    public function __construct(
        private readonly MagazineProjector $projector,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProjectMagazineMessage $message): void
    {
        $slug = $message->getSlug();

        $this->logger->info('Processing magazine projection message', [
            'slug' => $slug,
            'force' => $message->isForce(),
        ]);

        try {
            $magazine = $this->projector->projectMagazine($slug);

            if ($magazine) {
                $this->logger->info('Magazine projection successful', [
                    'slug' => $slug,
                    'id' => $magazine->getId(),
                ]);
            } else {
                $this->logger->warning('Magazine projection returned null', ['slug' => $slug]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Magazine projection failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
