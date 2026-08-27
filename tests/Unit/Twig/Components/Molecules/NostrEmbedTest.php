<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Twig\Components\Molecules\NostrEmbed;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NostrEmbedTest extends TestCase
{
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
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::PUBLICATION_CONTENT->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );

        $component->mount($naddr, 'naddr');

        self::assertTrue($component->resolved);
        self::assertTrue($component->isChapter);
        self::assertSame($event, $component->chapter);
        self::assertSame('/chapter/' . $naddr, $component->href);
        self::assertSame('chapter', $component->label);
    }
}
