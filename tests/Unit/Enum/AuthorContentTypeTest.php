<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AuthorContentType;
use App\Enum\KindsEnum;
use PHPUnit\Framework\TestCase;

class AuthorContentTypeTest extends TestCase
{
    public function testFromKindArticles(): void
    {
        $result = AuthorContentType::fromKind(KindsEnum::LONGFORM->value);
        $this->assertSame(AuthorContentType::ARTICLES, $result);
    }

    public function testFromKindDrafts(): void
    {
        $result = AuthorContentType::fromKind(KindsEnum::LONGFORM_DRAFT->value);
        $this->assertSame(AuthorContentType::DRAFTS, $result);
    }

    public function testFromKindMedia(): void
    {
        $result = AuthorContentType::fromKind(KindsEnum::IMAGE->value);
        $this->assertSame(AuthorContentType::MEDIA, $result);

        $result = AuthorContentType::fromKind(21); // video
        $this->assertSame(AuthorContentType::MEDIA, $result);

        $result = AuthorContentType::fromKind(22); // short video
        $this->assertSame(AuthorContentType::MEDIA, $result);
    }

    public function testFromKindHighlights(): void
    {
        $result = AuthorContentType::fromKind(KindsEnum::HIGHLIGHTS->value);
        $this->assertSame(AuthorContentType::HIGHLIGHTS, $result);
    }

    public function testFromKindBookmarks(): void
    {
        $result = AuthorContentType::fromKind(KindsEnum::BOOKMARKS->value);
        $this->assertSame(AuthorContentType::BOOKMARKS, $result);

        $result = AuthorContentType::fromKind(KindsEnum::CURATION_SET->value);
        $this->assertSame(AuthorContentType::BOOKMARKS, $result);
    }

    public function testFromKindInterests(): void
    {
        $result = AuthorContentType::fromKind(KindsEnum::INTERESTS->value);
        $this->assertSame(AuthorContentType::INTERESTS, $result);
    }

    public function testFromKindUnknownReturnsNull(): void
    {
        $this->assertNull(AuthorContentType::fromKind(99999));
    }

    public function testKindsForTypesReturnsUniqueKinds(): void
    {
        $types = [AuthorContentType::ARTICLES, AuthorContentType::MEDIA];
        $kinds = AuthorContentType::kindsForTypes($types);

        $this->assertContains(KindsEnum::LONGFORM->value, $kinds);
        $this->assertContains(KindsEnum::IMAGE->value, $kinds);
        $this->assertSame(array_unique($kinds), $kinds);
    }
}

