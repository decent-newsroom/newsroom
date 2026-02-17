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
}

