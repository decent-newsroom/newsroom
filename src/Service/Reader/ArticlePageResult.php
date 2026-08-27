<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Article;
use App\Service\Nostr\EventLookupKey;

final readonly class ArticlePageResult
{
    public const STATUS_READY = 'ready';
    public const STATUS_LOADING = 'loading';
    public const STATUS_ACCESS_REQUIRED = 'access_required';
    public const STATUS_NOT_FOUND = 'not_found';

    /**
     * @param array<int, mixed> $highlights
     */
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
        public array $highlights = [],
        public bool $isDraft = false,
        public ?string $notFoundMessage = null,
        public ?string $searchQuery = null,
    ) {
    }

    /**
     * @param array<int, mixed> $highlights
     */
    public static function ready(
        Article $article,
        \stdClass $author,
        string $npub,
        string $content,
        bool $canEdit,
        string $canonical,
        mixed $advancedMetadata,
        array $highlights = [],
        bool $isDraft = false,
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
            highlights: $highlights,
            isDraft: $isDraft,
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

    public static function notFound(string $message, string $searchQuery): self
    {
        return new self(
            status: self::STATUS_NOT_FOUND,
            notFoundMessage: $message,
            searchQuery: $searchQuery,
        );
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

    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }

    /**
     * @return array<string, mixed>
     */
    public function articleTemplateParameters(): array
    {
        $parameters = [
            'article' => $this->article,
            'author' => $this->author,
            'npub' => $this->npub,
            'content' => $this->content,
            'canEdit' => $this->canEdit,
            'canonical' => $this->canonical,
            'advancedMetadata' => $this->advancedMetadata,
        ];

        if ($this->isDraft) {
            $parameters['highlights'] = $this->highlights;
            $parameters['isDraft'] = true;
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadingTemplateParameters(): array
    {
        return [
            'lookupKey' => $this->lookupKey,
            'lookupTopic' => $this->lookupKey ? EventLookupKey::topic($this->lookupKey) : null,
            'reloadUrl' => $this->reloadUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function notFoundTemplateParameters(): array
    {
        return [
            'message' => $this->notFoundMessage,
            'searchQuery' => $this->searchQuery,
        ];
    }
}
