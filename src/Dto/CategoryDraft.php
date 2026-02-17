<?php

declare(strict_types=1);

namespace App\Dto;

class CategoryDraft
{
    public string $title = '';
    public string $summary = '';
    public string $image = '';
    /** @var string[] */
    public array $tags = [];
    /** @var string[] article coordinates like kind:pubkey:slug */
    public array $articles = [];
    public string $slug = '';

    /**
     * If set, this category references an existing list by coordinate (30040:pubkey:slug)
     * instead of creating a new one
     */
    public ?string $existingListCoordinate = null;

    /** Workflow state tracking */
    private string $workflowState = 'empty';

    public function getWorkflowState(): string
    {
        return $this->workflowState;
    }

    public function setWorkflowState(string $state): void
    {
        $this->workflowState = $state;
    }

    /**
     * Returns true if this draft references an existing list
     */
    public function isExistingList(): bool
    {
        return $this->existingListCoordinate !== null && $this->existingListCoordinate !== '';
    }

    /**
     * Returns true if this category is completely empty (no title and no coordinate)
     */
    public function isEmpty(): bool
    {
        return empty($this->title) && empty($this->existingListCoordinate);
    }
}
