<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Notification\NotificationRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NotificationRendererTest extends TestCase
{
    public function testPublicationWithNestedIndexLinksToGenericNaddrEventPage(): void
    {
        $renderer = new NotificationRenderer(new NullLogger());

        $event = $this->makeEvent(
            KindsEnum::PUBLICATION_INDEX->value,
            str_repeat('b', 64),
            'daily-news',
            [
                ['d', 'daily-news'],
                ['a', '30040:' . str_repeat('c', 64) . ':world'],
            ]
        );

        $rendered = $renderer->render($event);

        self::assertStringStartsWith('/e/naddr1', $rendered['url']);
    }

    public function testPublicationWithNestedArticlesLinksToGenericNaddrEventPage(): void
    {
        $renderer = new NotificationRenderer(new NullLogger());

        $event = $this->makeEvent(
            KindsEnum::PUBLICATION_INDEX->value,
            str_repeat('b', 64),
            'my-list',
            [
                ['d', 'my-list'],
                ['a', '30023:' . str_repeat('d', 64) . ':article-one'],
            ]
        );

        $rendered = $renderer->render($event);

        self::assertStringStartsWith('/e/naddr1', $rendered['url']);
    }

    public function testLongformLinksToGenericNaddrEventPage(): void
    {
        $renderer = new NotificationRenderer(new NullLogger());

        $event = $this->makeEvent(
            KindsEnum::LONGFORM->value,
            str_repeat('b', 64),
            'my-article',
            [
                ['d', 'my-article'],
                ['title', 'Hello'],
            ]
        );

        $rendered = $renderer->render($event);

        self::assertStringStartsWith('/e/naddr1', $rendered['url']);
    }

    public function testNonAddressableEventFallsBackToNoteRoute(): void
    {
        $renderer = new NotificationRenderer(new NullLogger());

        $event = $this->makeEvent(
            KindsEnum::TEXT_NOTE->value,
            str_repeat('b', 64),
            '',
            [
                ['content-warning', 'cw'],
            ]
        );

        $rendered = $renderer->render($event);

        self::assertStringStartsWith('/e/note1', $rendered['url']);
    }

    private function makeEvent(int $kind, string $pubkey, string $dTag, array $tags): Event
    {
        $e = new Event();
        $e->setId(str_repeat('a', 64));
        $e->setKind($kind);
        $e->setPubkey($pubkey);
        $e->setContent('');
        $e->setCreatedAt(time());
        $e->setTags($tags);
        $e->setSig(str_repeat('f', 128));
        $e->setDTag($dTag);

        return $e;
    }
}


