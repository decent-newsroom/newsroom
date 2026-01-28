<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Registry of author content types for extensible fetching
 * Maps content types to Nostr kinds, cache keys, and visibility rules
 */
enum AuthorContentType: string
{
    case ARTICLES = 'articles';
    case DRAFTS = 'drafts';
    case MEDIA = 'media';
    case HIGHLIGHTS = 'highlights';
    case BOOKMARKS = 'bookmarks';
    case INTERESTS = 'interests';

    /**
     * Get the Nostr kinds for this content type
     * @return int[]
     */
    public function getKinds(): array
    {
        return match($this) {
            self::ARTICLES => [KindsEnum::LONGFORM->value],
            self::DRAFTS => [KindsEnum::LONGFORM_DRAFT->value],
            self::MEDIA => [KindsEnum::IMAGE->value, 21, 22], // 20=image, 21=video, 22=audio (NIP-68/94)
            self::HIGHLIGHTS => [KindsEnum::HIGHLIGHTS->value],
            self::BOOKMARKS => [
                KindsEnum::BOOKMARKS->value,      // 10003 - standard bookmarks
                KindsEnum::BOOKMARK_SETS->value,  // 30003 - bookmark sets
                KindsEnum::CURATION_SET->value,   // 30004 - curation sets (articles/notes)
                KindsEnum::CURATION_VIDEOS->value, // 30005 - video curation sets
                KindsEnum::CURATION_PICTURES->value // 30006 - picture curation sets
            ],
            self::INTERESTS => [KindsEnum::INTERESTS->value],
        };
    }

    /**
     * Get the Redis cache key prefix for this content type
     */
    public function getCacheKeyPrefix(): string
    {
        return match($this) {
            self::ARTICLES => 'view:user:articles',
            self::DRAFTS => 'view:user:drafts',
            self::MEDIA => 'view:user:media',
            self::HIGHLIGHTS => 'view:user:highlights',
            self::BOOKMARKS => 'view:user:bookmarks',
            self::INTERESTS => 'view:user:interests',
        };
    }

    /**
     * Get the Mercure topic for this content type
     */
    public function getMercureTopic(string $pubkey): string
    {
        return sprintf('/author/%s/%s', $pubkey, $this->value);
    }

    /**
     * Whether this content type requires ownership (logged-in user viewing their own profile)
     */
    public function requiresOwnership(): bool
    {
        return match($this) {
            self::DRAFTS, self::BOOKMARKS => true,
            default => false,
        };
    }

    /**
     * Get all public content types (visible to everyone)
     * @return AuthorContentType[]
     */
    public static function publicTypes(): array
    {
        return array_filter(self::cases(), fn($type) => !$type->requiresOwnership());
    }

    /**
     * Get all private content types (owner only)
     * @return AuthorContentType[]
     */
    public static function privateTypes(): array
    {
        return array_filter(self::cases(), fn($type) => $type->requiresOwnership());
    }

    /**
     * Get all kinds as a flat array for a set of content types
     * @param AuthorContentType[] $types
     * @return int[]
     */
    public static function kindsForTypes(array $types): array
    {
        $kinds = [];
        foreach ($types as $type) {
            $kinds = array_merge($kinds, $type->getKinds());
        }
        return array_unique($kinds);
    }
}
