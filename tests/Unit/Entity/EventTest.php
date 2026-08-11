<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testTagGettersIgnoreMalformedTags(): void
    {
        $event = new Event();
        $event->setTags([
            ['summary'],
            ['title'],
            ['d'],
            'invalid-tag-shape',
        ]);

        self::assertNull($event->getSummary());
        self::assertNull($event->getTitle());
        self::assertNull($event->getSlug());
    }

    public function testTagGettersReturnValuesWhenPresent(): void
    {
        $event = new Event();
        $event->setTags([
            ['title', 'Test Title'],
            ['summary', 'Test Summary'],
            ['d', 'test-slug'],
        ]);

        self::assertSame('Test Title', $event->getTitle());
        self::assertSame('Test Summary', $event->getSummary());
        self::assertSame('test-slug', $event->getSlug());
    }
}

