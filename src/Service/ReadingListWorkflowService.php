<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CategoryDraft;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Service for managing reading list workflow transitions
 */
class ReadingListWorkflowService
{
    public function __construct(
        private readonly WorkflowInterface $readingListWorkflow,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Initialize a new reading list draft
     */
    public function initializeDraft(CategoryDraft $draft): void
    {
        if ($this->readingListWorkflow->can($draft, 'start_draft')) {
            $this->readingListWorkflow->apply($draft, 'start_draft');
            $this->logger->info('Reading list workflow: started draft', [
                'slug' => $draft->slug
            ]);
        }
    }

    /**
     * Update metadata (title/summary) and transition if needed
     */
    public function updateMetadata(CategoryDraft $draft): void
    {
        if ($draft->title !== '' && $this->readingListWorkflow->can($draft, 'add_metadata')) {
            $this->readingListWorkflow->apply($draft, 'add_metadata');
            $this->logger->info('Reading list workflow: metadata added', [
                'slug' => $draft->slug,
                'title' => $draft->title
            ]);
        }
    }

    /**
     * Add articles and transition if needed
     */
    public function addArticles(CategoryDraft $draft): void
    {
        if (!empty($draft->articles) && $this->readingListWorkflow->can($draft, 'add_articles')) {
            $this->readingListWorkflow->apply($draft, 'add_articles');
            $this->logger->info('Reading list workflow: articles added', [
                'slug' => $draft->slug,
                'count' => count($draft->articles)
            ]);
        }
    }

    /**
     * Mark as ready for review
     */
    public function markReadyForReview(CategoryDraft $draft): bool
    {
        if ($this->readingListWorkflow->can($draft, 'ready_for_review')) {
            $this->readingListWorkflow->apply($draft, 'ready_for_review');
            $this->logger->info('Reading list workflow: ready for review', [
                'slug' => $draft->slug
            ]);
            return true;
        }
        return false;
    }

    /**
     * Start the publishing process
     */
    public function startPublishing(CategoryDraft $draft): void
    {
        if ($this->readingListWorkflow->can($draft, 'start_publishing')) {
            $this->readingListWorkflow->apply($draft, 'start_publishing');
            $this->logger->info('Reading list workflow: publishing started', [
                'slug' => $draft->slug
            ]);
        }
    }

    /**
     * Complete the publishing process
     */
    public function completePublishing(CategoryDraft $draft): void
    {
        if ($this->readingListWorkflow->can($draft, 'complete_publishing')) {
            $this->readingListWorkflow->apply($draft, 'complete_publishing');
            $this->logger->info('Reading list workflow: published', [
                'slug' => $draft->slug
            ]);
        }
    }

    /**
     * Edit a published reading list
     */
    public function editPublished(CategoryDraft $draft): void
    {
        if ($this->readingListWorkflow->can($draft, 'edit_published')) {
            $this->readingListWorkflow->apply($draft, 'edit_published');
            $this->logger->info('Reading list workflow: editing published list', [
                'slug' => $draft->slug
            ]);
        }
    }

    /**
     * Cancel the draft
     */
    public function cancel(CategoryDraft $draft): void
    {
        if ($this->readingListWorkflow->can($draft, 'cancel')) {
            $this->readingListWorkflow->apply($draft, 'cancel');
            $this->logger->info('Reading list workflow: cancelled', [
                'slug' => $draft->slug
            ]);
        }
    }

    /**
     * Get current state of the reading list
     */
    public function getCurrentState(CategoryDraft $draft): string
    {
        return $draft->getWorkflowState();
    }

    /**
     * Get available transitions
     * @return array<string>
     */
    public function getAvailableTransitions(CategoryDraft $draft): array
    {
        return $this->readingListWorkflow->getEnabledTransitions($draft);
    }

    /**
     * Check if draft is ready to publish
     */
    public function isReadyToPublish(CategoryDraft $draft): bool
    {
        return $this->readingListWorkflow->can($draft, 'start_publishing');
    }

    /**
     * Get a human-readable status message
     */
    public function getStatusMessage(CategoryDraft $draft): string
    {
        return match ($draft->getWorkflowState()) {
            'empty' => 'Not started',
            'draft' => 'Draft created',
            'has_metadata' => 'Title and summary added',
            'has_articles' => 'Articles added',
            'ready_for_review' => 'Ready to publish',
            'publishing' => 'Publishing...',
            'published' => 'Published',
            'editing' => 'Editing published list',
            default => 'Unknown state',
        };
    }

    /**
     * Get a badge color for the current state
     */
    public function getStateBadgeColor(CategoryDraft $draft): string
    {
        return match ($draft->getWorkflowState()) {
            'empty' => 'secondary',
            'draft' => 'info',
            'has_metadata' => 'info',
            'has_articles' => 'primary',
            'ready_for_review' => 'success',
            'publishing' => 'warning',
            'published' => 'success',
            'editing' => 'warning',
            default => 'secondary',
        };
    }

    /**
     * Get completion percentage (for progress bar)
     */
    public function getCompletionPercentage(CategoryDraft $draft): int
    {
        return match ($draft->getWorkflowState()) {
            'empty' => 0,
            'draft' => 20,
            'has_metadata' => 40,
            'has_articles' => 60,
            'ready_for_review' => 80,
            'publishing' => 90,
            'published' => 100,
            'editing' => 50,
            default => 0,
        };
    }
}

