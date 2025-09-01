<?php

declare(strict_types=1);

namespace App\Dto;

class CategoryDraft
{
    public string $title = '';
    public string $summary = '';
    /** @var string[] */
    public array $tags = [];
    /** @var string[] article coordinates like kind:pubkey|npub:slug */
    public array $articles = [];
    public string $slug = '';
}

