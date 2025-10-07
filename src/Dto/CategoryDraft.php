<?php

declare(strict_types=1);

namespace App\Dto;

class CategoryDraft
{
    public string $title = '';
    public string $summary = '';
    /** @var string[] */
    public array $tags = [];
    /** @var string[] article coordinates like kind:pubkey:slug */
    public array $articles = [];
    public string $slug = '';

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
}
