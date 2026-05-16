<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class MagazineDraft
{
    #[Assert\NotBlank(message: 'Magazine title is required.')]
    public string $title = '';
    public string $summary = '';
    public string $imageUrl = '';
    public ?string $language = null;
    /** @var string[] */
    public array $tags = [];
    /** @var CategoryDraft[] */
    #[Assert\Valid]
    public array $categories = [];
    public string $slug = '';

    /**
     * Admin-only: npub to receive 100% zap split on the magazine and its new categories.
     */
    public ?string $zapSplitNpub = null;

    /**
     * Optional author display name. When set, supersedes the publishing npub in bylines
     * and is stored as an 'author' tag on the magazine event.
     */
    public ?string $author = null;
}

