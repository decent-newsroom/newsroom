<?php

declare(strict_types=1);

namespace App\Dto;

class MagazineDraft
{
    public string $title = '';
    public string $summary = '';
    public string $imageUrl = '';
    public ?string $language = null;
    /** @var string[] */
    public array $tags = [];
    /** @var CategoryDraft[] */
    public array $categories = [];
    public string $slug = '';
}

