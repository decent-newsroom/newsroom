<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrNip19Service;
use App\Twig\Components\Molecules\NostrEmbed;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NostrEmbedTest extends TestCase
{
    public function testLongformWithoutArticleProjectionRemainsAResolvedEventWhenConversionFails(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'local-only';
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(KindsEnum::LONGFORM->value);
        $event->setPubkey($pubkey);
        $event->setContent('Original article text');
        $event->setCreatedAt(123);
        $event->setTags([['d', $identifier], ['title', 'Local article'], ['summary', 'From tags']]);
        $event->setSig(str_repeat('c', 128));

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::LONGFORM->value, $pubkey, $identifier)
            ->willReturn($event);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findOneBy')->willReturn(null);
        $factory = $this->createMock(ArticleFactory::class);
        $factory->expects(self::once())
            ->method('createFromLongFormContentEvent')
            ->willThrowException(new \RuntimeException('Cannot convert body'));

        $component = new NostrEmbed(
            $repository,
            $articles,
            $factory,
            $this->createMock(RedisCacheService::class),
            new NullLogger(),
        );
        $naddr = (new NostrNip19Service())->encodeAddr($pubkey, $identifier, KindsEnum::LONGFORM->value);

        $component->mount($naddr, 'naddr');

        self::assertTrue($component->resolved);
        self::assertTrue($component->isLongform);
        self::assertNull($component->article);
        self::assertSame($event->getId(), $component->event->id);
        self::assertSame($event->getTags(), $component->event->tags);
        self::assertSame('/article/' . $naddr, $component->href);
    }

    public function testResolveNaddrMarksPublicationContentAsChapter(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'intro';
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(KindsEnum::PUBLICATION_CONTENT->value);
        $event->setPubkey($pubkey);
        $event->setContent('Chapter body');
        $event->setCreatedAt(123);
        $event->setTags([['d', $identifier], ['title', 'Intro']]);
        $event->setSig(str_repeat('c', 128));
        $event->setDTag($identifier);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, $identifier)
            ->willReturn($event);

        $component = new NostrEmbed(
            $repository,
            $this->createMock(ArticleRepository::class),
            $this->createMock(ArticleFactory::class),
            $this->createMock(RedisCacheService::class),
            new NullLogger(),
        );
        $naddr = (new NostrNip19Service())->encodeAddr($pubkey, $identifier, KindsEnum::PUBLICATION_CONTENT->value);

        $component->mount($naddr, 'naddr');

        self::assertTrue($component->resolved);
        self::assertTrue($component->isChapter);
        self::assertSame($event, $component->chapter);
        self::assertSame('/chapter/' . $naddr, $component->href);
        self::assertSame('chapter', $component->label);
    }
}
