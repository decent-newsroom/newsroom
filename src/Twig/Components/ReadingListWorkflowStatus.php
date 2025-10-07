<?php

namespace App\Twig\Components;

use App\Dto\CategoryDraft;
use App\Service\ReadingListWorkflowService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ReadingListWorkflowStatus
{
    public CategoryDraft $draft;

    public function __construct(
        private readonly ReadingListWorkflowService $workflowService,
    ) {}

    public function getStatusMessage(): string
    {
        return $this->workflowService->getStatusMessage($this->draft);
    }

    public function getBadgeColor(): string
    {
        return $this->workflowService->getStateBadgeColor($this->draft);
    }

    public function getCompletionPercentage(): int
    {
        return $this->workflowService->getCompletionPercentage($this->draft);
    }

    public function isReadyToPublish(): bool
    {
        return $this->workflowService->isReadyToPublish($this->draft);
    }

    public function getCurrentState(): string
    {
        return $this->workflowService->getCurrentState($this->draft);
    }

    public function getNextSteps(): array
    {
        $state = $this->getCurrentState();

        return match ($state) {
            'empty', 'draft' => [
                'Add a title and summary',
                'Add articles to your list',
            ],
            'has_metadata' => [
                'Add articles to your list',
            ],
            'has_articles' => [
                'Review your list',
                'Click "Review & Publish" when ready',
            ],
            'ready_for_review' => [
                'Review the event JSON',
                'Sign and publish with your Nostr extension',
            ],
            'publishing' => [
                'Please wait...',
            ],
            'published' => [
                'Your reading list is live!',
                'Share the link with others',
            ],
            'editing' => [
                'Add or remove articles',
                'Update title or summary',
                'Republish when done',
            ],
            default => [],
        };
    }
}

