<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Article;

final readonly class ArticlePageResult
{
    public const STATUS_READY = 'ready';
    public const STATUS_LOADING = 'loading';
    public const STATUS_ACCESS_REQUIRED = 'access_required';

    private function __construct(
        public string $status,
        public ?Article $article = null,
        public ?\stdClass $author = null,
        public ?string $npub = null,
        public ?string $content = null,
        public bool $canEdit = false,
        public ?string $canonical = null,
        public mixed $advancedMetadata = null,
        public ?string $lookupKey = null,
        public ?string $reloadUrl = null,
    ) {
    }

    public static function ready(
        Article $article,
        \stdClass $author,
        string $npub,
        string $content,
        bool $canEdit,
        string $canonical,
        mixed $advancedMetadata,
    ): self {
        return new self(
            status: self::STATUS_READY,
            article: $article,
            author: $author,
            npub: $npub,
            content: $content,
            canEdit: $canEdit,
            canonical: $canonical,
            advancedMetadata: $advancedMetadata,
        );
    }

    public static function loading(string $lookupKey, string $reloadUrl): self
    {
        return new self(
            status: self::STATUS_LOADING,
            lookupKey: $lookupKey,
            reloadUrl: $reloadUrl,
        );
    }

    public static function accessRequired(): self
    {
        return new self(status: self::STATUS_ACCESS_REQUIRED);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isLoading(): bool
    {
        return $this->status === self::STATUS_LOADING;
    }

    public function requiresAccess(): bool
    {
        return $this->status === self::STATUS_ACCESS_REQUIRED;
    }

    /**
     * @return array<string, mixed>
     */
    public function articleTemplateParameters(): array
    {
        return [
            'article' => $this->article,
            'author' => $this->author,
            'npub' => $this->npub,
            'content' => $this->content,
            'canEdit' => $this->canEdit,
            'canonical' => $this->canonical,
            'advancedMetadata' => $this->advancedMetadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadingTemplateParameters(): array
    {
        return [
            'lookupKey' => $this->lookupKey,
            'reloadUrl' => $this->reloadUrl,
        ];
    }
}